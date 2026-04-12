<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = [
        'meta_template_id',
        'name',
        'language',
        'category',
        'content',
        'header_content',
        'footer_content',
        'status',
        'quality_score',
        'parameters',
    ];

    protected $casts = [
        'parameters' => 'array',
    ];

    public static function parseParameters(string $content): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $content, $matches);
        $params = array_unique($matches[1] ?? []);
        
        return array_map(fn($num) => [
            'key' => (int) $num,
            'label' => 'Parameter ' . $num,
            'type' => 'text',
        ], array_values($params));
    }

    public function getParametersAttribute($value): array
    {
        if ($value) {
            return $value;
        }

        return self::parseParameters($this->content ?? '');
    }
}
