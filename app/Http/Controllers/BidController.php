<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Bid;
use App\Services\JobUrlParserService;
use Carbon\Carbon;

class BidController extends Controller
{
    public function checkCollision(Request $request, JobUrlParserService $parser)
    {
        $request->validate(['url' => 'required|url']);

        $cleanId = $parser->extractCleanJobId($request->url);

        if (!$cleanId) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or unsupported URL format.'], 400);
        }

        $existingBid = Bid::where('clean_job_id', $cleanId)
            ->where('user_id', '!=', auth()->id())
            ->first();

        if ($existingBid) {
            return response()->json([
                'status' => 'collision',
                'message' => 'Another bidder in your agency has already bid on this job!',
                'bid' => $existingBid
            ]);
        }

        $ownBid = Bid::where('clean_job_id', $cleanId)
            ->where('user_id', auth()->id())
            ->first();

        if ($ownBid) {
            return response()->json([
                'status' => 'clear',
                'message' => 'You already bid on this job. No collision with teammates.',
                'clean_job_id' => $cleanId,
                'already_submitted' => true,
            ]);
        }

        return response()->json(['status' => 'clear', 'message' => 'Safe to bid!', 'clean_job_id' => $cleanId]);
    }

    public function submit(Request $request, JobUrlParserService $parser)
    {
        $request->validate([
            'url' => 'required|url',
            'connects' => 'required|integer|min:1',
            'platform' => 'required|string',
            'job_title' => 'nullable|string|max:255',
        ]);

        $cleanId = $parser->extractCleanJobId($request->url);

        $bid = Bid::create([
            'tenant_id' => auth()->user()->tenant_id,
            'user_id' => auth()->id(),
            'job_url' => $request->url,
            'clean_job_id' => $cleanId,
            'platform_name' => $request->platform,
            'connects_used' => $request->connects,
            'job_title' => $request->job_title,
            'status' => 'Submitted'
        ]);

        return response()->json(['status' => 'success', 'bid' => $bid]);
    }

    public function myBids(Request $request)
    {
        $query = Bid::where('user_id', auth()->id())->orderBy('created_at', 'desc');

        if ($request->filled('filter')) {
            $filter = $request->filter;

            if ($filter === 'today') {
                $query->whereDate('created_at', today());
            } elseif ($filter === '7days') {
                $query->where('created_at', '>=', now()->subDays(7));
            } elseif ($filter === '30days') {
                $query->where('created_at', '>=', now()->subDays(30));
            } elseif ($filter === 'this_month') {
                $query->whereMonth('created_at', now()->month)
                      ->whereYear('created_at', now()->year);
            } elseif ($filter === 'custom' && $request->filled('from') && $request->filled('to')) {
                $from = Carbon::parse($request->from)->startOfDay();
                $to = Carbon::parse($request->to)->endOfDay();
                $query->whereBetween('created_at', [$from, $to]);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $bids = $query->paginate(20);

        $stats = [
            'total' => Bid::where('user_id', auth()->id())->count(),
            'today' => Bid::where('user_id', auth()->id())->whereDate('created_at', today())->count(),
            'this_week' => Bid::where('user_id', auth()->id())->where('created_at', '>=', now()->startOfWeek())->count(),
            'connects_today' => Bid::where('user_id', auth()->id())->whereDate('created_at', today())->sum('connects_used'),
        ];

        return response()->json([
            'bids' => $bids,
            'stats' => $stats,
        ]);
    }

    public function analyzeJob(Request $request)
    {
        $request->validate([
            'job_text' => 'required|string|min:20|max:15000',
        ]);

        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            return response()->json(['error' => 'Gemini API key not configured'], 500);
        }

        $today = now('Asia/Kolkata')->format('F j, Y');

        $tenant = \App\Models\Tenant::find(auth()->user()->tenant_id);
        $agencyProfile = $tenant->agency_profile;
        $agencyContext = '';
        if ($agencyProfile && array_filter($agencyProfile)) {
            $parts = [];
            if (!empty($agencyProfile['description'])) $parts[] = "About: {$agencyProfile['description']}";
            if (!empty($agencyProfile['skills'])) $parts[] = "Skills: {$agencyProfile['skills']}";
            if (!empty($agencyProfile['tech_stack'])) $parts[] = "Tech Stack: {$agencyProfile['tech_stack']}";
            if (!empty($agencyProfile['can_build'])) $parts[] = "Can Build: {$agencyProfile['can_build']}";
            $agencyContext = "\n\nAGENCY PROFILE (use this to evaluate feasibility):\n" . implode("\n", $parts);
        }

        $prompt = <<<PROMPT
You are an Upwork job analyst for a freelancer based in India. Today's date is {$today}. Analyze the job posting below for red flags. When evaluating dates (e.g. "Member since"), compare against today's date — do NOT treat current or recent dates as future/fake.{$agencyContext}

Rate each applicable flag as red (critical risk), yellow (caution), or green (good):
- Payment Verified — if explicitly says "unverified" or "not verified" = red. If not mentioned but client has past spending ($1+) or hires, mark yellow with "Likely verified (client has payment history) — confirm on profile". If $0 spent and not mentioned = red
- Client Hire Rate — below 50% = red
- Client Reviews — poor rating = red. No reviews = yellow. If not mentioned but client has hires/spending, mark yellow with "Check client rating directly on Upwork profile"
- Client Spending — zero or very low = red
- Client Country — countries with high scam/low-pay patterns = yellow/red
- Proposals Count — 50+ = red (too competitive)
- Invites Sent — many invites = yellow (mass-interviewing)
- Interviewing — many candidates = yellow
- Timezone — ONLY flag if the job description explicitly requires working in a specific timezone or specific hours. Client's location timezone alone is NOT a red flag. Many clients hire globally and don't care about timezone
- Budget — ONLY flag if a specific dollar budget is stated AND it is clearly too low for the work described (e.g. $50 for a full app). Do NOT flag project duration or hourly jobs without a budget cap. Duration alone is not a budget issue
- Job Description — vague or scope creep = yellow/red
- Contract Type — fixed price + unclear scope = red
- Feasibility — ONLY if agency profile is provided: check if the job's required skills/tech match the agency's capabilities. green = strong match, yellow = partial match, red = agency lacks the required skills entirely

Respond ONLY with valid JSON, no markdown fences, no extra text. Keep each detail under 30 words:
{"overall_score":<1-10>,"verdict":"<SAFE TO BID|PROCEED WITH CAUTION|AVOID>","summary":"<1 sentence>","flags":[{"label":"<name>","level":"<red|yellow|green>","detail":"<brief reason>"}]}

Skip flags you cannot determine from the text. Skip Feasibility if no agency profile was provided.

JOB POSTING:
{$request->job_text}
PROMPT;

        try {
            $response = Http::timeout(30)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 4096,
                    ],
                ]
            );

            if (!$response->ok()) {
                return response()->json(['error' => 'AI service error'], 502);
            }

            $text = $response->json('candidates.0.content.parts.0.text', '');
            $text = preg_replace('/^```json\s*|```\s*$/m', '', trim($text));
            $analysis = json_decode($text, true);

            if (!$analysis || !isset($analysis['flags'])) {
                return response()->json(['error' => 'Could not parse AI response'], 502);
            }

            return response()->json($analysis);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI analysis failed: ' . $e->getMessage()], 500);
        }
    }
}
