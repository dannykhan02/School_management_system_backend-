<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'enabled',
        'pattern',
        'prefix',
        'separator',
        'include_year',
        'year_format',
        'number_padding',
        'current_sequence',
        'sequence_start',
        'reset_yearly',
        'last_reset_year',
        'allow_manual_override',
        'configured_by',
    ];

    protected $casts = [
        'enabled'               => 'boolean',
        'include_year'          => 'boolean',
        'reset_yearly'          => 'boolean',
        'allow_manual_override' => 'boolean',
        'current_sequence'      => 'integer',
        'sequence_start'        => 'integer',
        'number_padding'        => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────────

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }

    // ── Preview helper ───────────────────────────────────────────────────────────

    /**
     * Returns what the NEXT admission number will look like, without incrementing.
     * Use this for the admin config UI live preview.
     *
     * @param  string|null  $forYear  e.g. "2025" — defaults to current year
     */
    public function previewNextNumber(?string $forYear = null): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $year     = $forYear ?? now()->format('Y');
        $sequence = $this->current_sequence + 1;

        return $this->buildNumber($sequence, $year);
    }

    /**
     * Build a formatted admission number from a raw sequence integer.
     * This is a pure utility — it does NOT touch the database.
     */
    public function buildNumber(int $sequence, ?string $year = null): string
    {
        $year = $year ?? now()->format('Y');

        $paddedNumber = str_pad($sequence, $this->number_padding, '0', STR_PAD_LEFT);
        $yearToken    = $this->year_format === 'YY' ? substr($year, -2) : $year;

        $result = str_replace(
            ['{PREFIX}', '{YEAR}', '{NUMBER}', '{SEP}'],
            [$this->prefix ?? '', $yearToken, $paddedNumber, $this->separator],
            $this->pattern
        );

        // Clean up any empty token residue e.g. "//001" if prefix was null
        // Normalise consecutive separators that appear because a token was empty
        $sep = preg_quote($this->separator, '/');
        if ($this->separator !== '') {
            $result = preg_replace('/(' . $sep . ')+/', $this->separator, $result);
            $result = trim($result, $this->separator);
        }

        return $result;
    }

    // ── Scope ────────────────────────────────────────────────────────────────────

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}