<?php

namespace Tests\Feature;

use App\Jobs\SendGroupUnseenEmail;
use App\Models\EmailTemplate;
use App\Models\Conversation;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GroupChatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $ceo;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        Role::findOrCreate('TenantAdmin');
        $this->tenant = Tenant::create(['company_name' => 'Acme']);

        $ceoPosition = Position::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->tenant_id, 'title' => 'CEO', 'can_create_groups' => true,
        ]);

        $this->ceo = $this->member('Carol Ceo');
        $this->ceo->positions()->attach($ceoPosition->id);
        $this->alice = $this->member('Alice Smith');
        $this->bob = $this->member('Bob Jones');
    }

    private function member(string $name): User
    {
        return User::factory()->create(['name' => $name, 'tenant_id' => $this->tenant->tenant_id, 'is_active' => true]);
    }

    private function createGroup(): Conversation
    {
        $id = $this->actingAs($this->ceo)->postJson('/api/messages/groups', [
            'title' => 'Leadership',
            'member_ids' => [$this->alice->id, $this->bob->id],
        ])->assertOk()->json('conversation_id');

        return Conversation::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_only_permitted_positions_can_create_groups(): void
    {
        $this->actingAs($this->alice)->postJson('/api/messages/groups', [
            'title' => 'Nope', 'member_ids' => [$this->bob->id],
        ])->assertForbidden();

        $group = $this->createGroup();
        $this->assertSame('group', $group->type);
        $this->assertSame($this->ceo->id, $group->created_by);
        $this->assertSame(3, $group->participants()->count());
    }

    public function test_mentions_reactions_and_pins(): void
    {
        $group = $this->createGroup();

        $msg = $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", [
            'body' => 'Hey @Bob Jones',
            'mentions' => [$this->bob->id, 99999],
        ])->assertOk()->json();
        $this->assertSame([$this->bob->id], $msg['mentions']);

        $this->actingAs($this->bob)->postJson("/api/messages/{$msg['id']}/react", ['emoji' => '👍'])
            ->assertOk()->assertJsonPath('reactions.0.count', 1);
        $this->actingAs($this->ceo)->postJson("/api/messages/{$msg['id']}/react", ['emoji' => '👍'])
            ->assertJsonPath('reactions.0.count', 2);
        // Reacting again with the same emoji removes it.
        $this->actingAs($this->ceo)->postJson("/api/messages/{$msg['id']}/react", ['emoji' => '👍'])
            ->assertJsonPath('reactions.0.count', 1);

        $this->actingAs($this->bob)->postJson("/api/messages/{$msg['id']}/pin")->assertOk();
        $this->actingAs($this->alice)->getJson("/api/messages/conversations/{$group->id}/pinned")
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.pinned_by.id', $this->bob->id);
    }

    public function test_everyone_mention_tags_all_other_members(): void
    {
        $group = $this->createGroup();

        $msg = $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", [
            'body' => 'Standup in 5 @everyone',
        ])->assertOk()->json();

        $this->assertEqualsCanonicalizing([$this->ceo->id, $this->bob->id], $msg['mentions']);
    }

    public function test_group_messages_queue_unseen_emails(): void
    {
        $group = $this->createGroup();
        $this->bob->forceFill(['presence_status' => 'online', 'last_active_at' => now()])->save();
        Queue::fake();

        // Plain message: everyone else gets a delayed "unseen" email.
        $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => 'plain'])->assertOk();
        Queue::assertPushed(SendGroupUnseenEmail::class, fn ($job) => $job->recipientId === $this->ceo->id && $job->delay !== null);
        Queue::assertPushed(SendGroupUnseenEmail::class, fn ($job) => $job->recipientId === $this->bob->id && $job->delay !== null);

        // A burst doesn't queue more while one is pending...
        Queue::fake();
        $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => 'again'])->assertOk();
        Queue::assertNothingPushed();

        // ...and once the window has passed, the next message queues a new one (10s wait, online or not).
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => 'later'])->assertOk();
        Queue::assertPushed(SendGroupUnseenEmail::class, fn ($job) => $job->recipientId === $this->bob->id
            && abs(now()->diffInSeconds($job->delay)) <= 10);
    }

    public function test_unseen_email_contains_latest_two_and_is_sent_once(): void
    {
        EmailTemplate::create([
            'slug' => 'new-message', 'name' => 'New message',
            'subject' => '{{sender_name}} sent you a message',
            'body_html' => '<p>{{message_preview}}</p>',
        ]);
        $group = $this->createGroup();
        Queue::fake(); // send() shouldn't run jobs inline here

        foreach (['first', 'second', 'third'] as $body) {
            $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => $body])->assertOk();
        }

        (new SendGroupUnseenEmail($this->bob->id, $group->id))->handle();
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $html = $sent[0]->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('second', $html);
        $this->assertStringContainsString('third', $html);
        $this->assertStringNotContainsString('first', $html);
        $this->assertStringContainsString('+1 more unread message', $html);
        $this->assertSame('Alice Smith (Leadership) sent you a message', $sent[0]->getOriginalMessage()->getSubject());

        // Same messages are never emailed twice.
        (new SendGroupUnseenEmail($this->bob->id, $group->id))->handle();
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());

        // Nothing is sent once the member has read the group.
        $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => 'fourth'])->assertOk();
        $this->actingAs($this->ceo)->postJson("/api/messages/conversations/{$group->id}/read")->assertOk();
        (new SendGroupUnseenEmail($this->ceo->id, $group->id))->handle();
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_owner_sees_who_has_seen_a_message(): void
    {
        $group = $this->createGroup();

        $msgId = $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => 'Read me'])
            ->json('id');

        $this->actingAs($this->bob)->postJson("/api/messages/conversations/{$group->id}/read")->assertOk();

        // Members can't see receipts; the owner can.
        $this->actingAs($this->bob)->getJson("/api/messages/{$msgId}/seen")->assertForbidden();

        $res = $this->actingAs($this->ceo)->getJson("/api/messages/{$msgId}/seen")->assertOk();
        $this->assertSame([$this->bob->id], array_column($res->json('seen'), 'id'));
        $this->assertNotNull($res->json('seen.0.seen_at'));
        $this->assertSame([$this->ceo->id], array_column($res->json('not_seen'), 'id'));

        // Owner's history carries a per-message counter; members' doesn't.
        $row = collect($this->actingAs($this->ceo)->getJson("/api/messages/conversations/{$group->id}/messages")->json('data'))
            ->firstWhere('id', $msgId);
        $this->assertSame(1, $row['seen_count']);
        $this->assertSame(2, $row['audience_count']);

        $memberRow = collect($this->actingAs($this->alice)->getJson("/api/messages/conversations/{$group->id}/messages")->json('data'))
            ->firstWhere('id', $msgId);
        $this->assertArrayNotHasKey('seen_count', $memberRow);
    }

    public function test_owner_controls_who_can_send_and_can_kick(): void
    {
        $group = $this->createGroup();

        // Members cannot manage the group.
        $this->actingAs($this->alice)->deleteJson("/api/messages/groups/{$group->id}/members/{$this->bob->id}")->assertForbidden();

        $this->actingAs($this->ceo)->putJson("/api/messages/groups/{$group->id}/members/{$this->alice->id}", ['can_send' => false])->assertOk();
        $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => 'hi'])->assertForbidden();

        $this->actingAs($this->ceo)->putJson("/api/messages/groups/{$group->id}/members/all", ['can_send' => true])->assertOk();
        $this->actingAs($this->alice)->postJson("/api/messages/conversations/{$group->id}/send", ['body' => 'hi'])->assertOk();

        $this->actingAs($this->ceo)->deleteJson("/api/messages/groups/{$group->id}/members/{$this->bob->id}")
            ->assertOk()->assertJsonPath('system_messages.0.body', 'Bob Jones was removed from the group by Carol Ceo');

        $lines = $group->messages()->where('type', 'system')->pluck('body')->all();
        $this->assertContains('Alice Smith and Bob Jones joined the group · added by Carol Ceo', $lines);
        $this->actingAs($this->bob)->getJson("/api/messages/conversations/{$group->id}/messages")->assertForbidden();
    }

    public function test_owner_leaving_hands_over_ownership(): void
    {
        $group = $this->createGroup();

        $this->actingAs($this->ceo)->postJson("/api/messages/groups/{$group->id}/leave")->assertOk();

        $group->refresh();
        $this->assertSame($this->alice->id, $group->created_by);
        $this->actingAs($this->alice)->getJson("/api/messages/groups/{$group->id}")
            ->assertOk()->assertJsonPath('is_owner', true);
    }

    public function test_invite_link_lets_teammates_join(): void
    {
        $group = $this->actingAs($this->ceo)->postJson('/api/messages/groups', [
            'title' => 'Leadership', 'member_ids' => [$this->alice->id],
        ])->json('conversation_id');

        // Only the owner can create the link.
        $this->actingAs($this->alice)->postJson("/api/messages/groups/{$group}/invite-link")->assertForbidden();

        $url = $this->actingAs($this->ceo)->postJson("/api/messages/groups/{$group}/invite-link")->assertOk()->json('invite_url');
        $token = substr($url, strpos($url, 'join=') + 5);

        $this->actingAs($this->bob)->postJson("/api/messages/groups/join/{$token}")
            ->assertOk()->assertJsonPath('conversation_id', $group)->assertJsonPath('already_member', false);
        $this->actingAs($this->bob)->getJson("/api/messages/conversations/{$group}/messages")->assertOk();

        // Someone from another tenant cannot use it.
        $otherTenant = Tenant::create(['company_name' => 'Other']);
        $outsider = User::factory()->create(['tenant_id' => $otherTenant->tenant_id, 'is_active' => true]);
        $this->actingAs($outsider)->postJson("/api/messages/groups/join/{$token}")->assertNotFound();

        // Resetting invalidates the old link.
        $this->actingAs($this->ceo)->postJson("/api/messages/groups/{$group}/invite-link")->assertOk();
        $dave = $this->member('Dave Doe');
        $this->actingAs($dave)->postJson("/api/messages/groups/join/{$token}")->assertNotFound();
    }

    public function test_direct_chat_lookup_ignores_groups(): void
    {
        // A 2-person group must not be reused as the 1:1 conversation.
        $this->actingAs($this->ceo)->postJson('/api/messages/groups', [
            'title' => 'Pair', 'member_ids' => [$this->alice->id],
        ])->assertOk();

        $directId = $this->actingAs($this->ceo)->postJson('/api/messages/conversations/start', ['user_id' => $this->alice->id])
            ->json('conversation_id');

        $this->assertSame('direct', Conversation::withoutGlobalScopes()->find($directId)->type);
    }
}
