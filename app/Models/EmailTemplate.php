<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['slug', 'name', 'subject', 'body_html'];

    public static function render(string $slug, array $variables = []): ?array
    {
        $template = static::where('slug', $slug)->first();

        if (!$template) {
            return null;
        }

        $subject = $template->subject;
        $body = $template->body_html;

        foreach ($variables as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
