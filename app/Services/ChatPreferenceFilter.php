<?php

namespace App\Services;

use App\Models\CustomerPreference;
use App\Models\FurnitureProduct;

class ChatPreferenceFilter
{
    public function parse(string $message, CustomerPreference $preference): array
    {
        $text = mb_strtolower(trim($message));
        $updates = [];
        $labels = [];
        // Negations and comparisons are questions, not permission to select a material.
        if (preg_match('/\b(not|don.t|without|hindi|ayaw|versus|vs)\b/u', $text)) return [];

        if (preg_match('/\b(below|under|less than|up to|maximum|max|budget|hanggang)\s*(?:(?:of|ko|is|ay|na|lang)\s*)*[:=]?\s*(php\s*|₱\s*)?([0-9]+(?:,[0-9]{3})*(?:\.[0-9]+)?)\s*(k|thousand)?\b/u', $text, $match)) {
            $amount = (float) str_replace(',', '', $match[3]);
            $short = empty($match[2]) && empty($match[4]) && $amount > 0 && $amount < 1000;
            if (! empty($match[4]) || $short) $amount *= 1000;
            $exclusive = in_array($match[1], ['below', 'under', 'less than'], true);
            if ($amount > 0 && $amount <= 100000000) {
                $updates['budget_max'] = round($amount - ($exclusive ? 0.01 : 0), 2);
                if ((float) $preference->budget_min >= $updates['budget_max']) $updates['budget_min'] = 0;
                $labels[] = ($exclusive ? 'below' : 'up to').' PHP '.number_format($amount, 2).($short ? ' (interpreting '.$match[3].' as thousands)' : '');
            }
        }

        $question = preg_match('/\b(what|why|difference|explain|ano|bakit)\b/u', $text);
        $changeRequest = ! $question && preg_match('/\b(show|use|choose|prefer|want|gusto|palitan|gawin|hanap|looking for|only|lang)\b/u', $text);

        foreach (['wood_type' => 'wood', 'style' => 'style', 'varnish_finish' => 'finish', 'furniture_type' => 'type'] as $field => $label) {
            if ($field !== 'furniture_type' && preg_match('/\bany\s+'.($field === 'wood_type' ? '(?:wood|material)' : $label).'\b/u', $text)) {
                $updates[$field] = null;
                $labels[] = 'any '.$label;
                continue;
            }
            $values = ($field === 'furniture_type' ? FurnitureProduct::recommendationTypes() : FurnitureProduct::where('status', 'active')->distinct()->pluck($field))->filter()
                ->sortByDesc(fn ($value) => mb_strlen($value));
            foreach ($values as $value) {
                $quoted = preg_quote(mb_strtolower($value), '/');
                if ($field === 'wood_type' && preg_match('/(?:\bfinish\s*[:=]?\s*'.$quoted.'\b|\b'.$quoted.'\s+finish\b)/u', $text)) continue;
                // A bare value or a selection phrase changes the filter; questions keep it unchanged.
                $selection = ! $question && (preg_match('/^(?:only\s+)?'.$quoted.'(?:\s+(?:only|lang|na lang))?[.!]?$/u', $text)
                    || preg_match('/\b(?:show|use|choose|prefer|gusto|only)\s+(?:me\s+)?'.$quoted.'\b/u', $text)
                    || preg_match('/\b'.$quoted.'\s+(?:only|lang|na lang)\b/u', $text)
                    || ($changeRequest && preg_match('/(?<![\pL\pN])'.$quoted.'(?![\pL\pN])/u', $text))
                    || preg_match('/^(?:'.$label.'\s*[:=]?\s*'.$quoted.'|'.$quoted.'\s+'.$label.')[.!]?$/u', $text));
                if ($selection) {
                    // Mahogany alone means wood; finish changes require an explicit finish label.
                    if ($field === 'varnish_finish' && array_key_exists('wood_type', $updates) && ! str_contains($text, 'finish')) continue;
                    $updates[$field] = $value;
                    $labels[] = $label.': '.$value;
                    break;
                }
            }
        }
        return $updates ? ['updates' => $updates, 'description' => implode('; ', $labels)] : [];
    }
}
