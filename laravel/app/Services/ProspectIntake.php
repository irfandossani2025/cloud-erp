<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProspectIntake
{
    public function __construct(private LeadAssigner $assigner) {}

    public const TEXT_FIELDS = ['name', 'title', 'company', 'email', 'phone', 'website', 'linkedin_url', 'location', 'source_url', 'notes'];

    /** Canonical form so the same person captured twice is recognised. */
    public static function linkedin(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (preg_match('/(^|\.)linkedin\.com$/', $host) && preg_match('#^/(in|company)/([^/?\#]+)#i', $parts['path'] ?? '', $m)) {
            return 'https://www.linkedin.com/'.strtolower($m[1]).'/'.strtolower(rawurldecode($m[2]));
        }

        return $url;
    }

    /**
     * @param  array  $data  name,title,company,email,phone,website,linkedin_url,location,source_url,notes
     * @return array{id:string,duplicate:?string,agent:?string,created:bool}
     *                                                                       duplicate is 'prospect' or 'customer' when it already existed.
     */
    public function add(array $data, string $source, string $capturedBy, ?string $assignTo = null): array
    {
        $row = [];
        foreach (self::TEXT_FIELDS as $f) {
            $row[$f] = trim((string) ($data[$f] ?? ''));
        }
        $row['email'] = strtolower($row['email']);
        $row['linkedin_url'] = self::linkedin($row['linkedin_url']);

        if ($row['email'] !== '' && ($c = DB::table('customers')->whereRaw('lower(email) = ?', [$row['email']])->first())) {
            return ['id' => $c->id, 'duplicate' => 'customer', 'agent' => $c->agent, 'created' => false];
        }
        $existing = DB::table('prospects')->where(function ($q) use ($row) {
            $any = false;
            if ($row['linkedin_url'] !== '') {
                $q->orWhere('linkedin_url', $row['linkedin_url']);
                $any = true;
            }
            if ($row['email'] !== '') {
                $q->orWhere('email', $row['email']);
                $any = true;
            }
            if ($row['name'] !== '' && $row['company'] !== '') {
                $q->orWhere(fn ($w) => $w->whereRaw('lower(name) = ?', [mb_strtolower($row['name'])])->whereRaw('lower(company) = ?', [mb_strtolower($row['company'])]));
                $any = true;
            }
            if (!$any) {
                $q->whereRaw('1 = 0');
            }
        })->first();
        if ($existing) {
            // Top up blanks only; never overwrite what an agent has already edited.
            $fill = [];
            foreach (['title', 'company', 'email', 'phone', 'website', 'linkedin_url', 'location'] as $f) {
                if ($existing->$f === '' && $row[$f] !== '') {
                    $fill[$f] = $row[$f];
                }
            }
            if ($fill) {
                DB::table('prospects')->where('id', $existing->id)->update([...$fill, 'updated' => now()->toIso8601String()]);
            }

            return ['id' => $existing->id, 'duplicate' => 'prospect', 'agent' => $existing->agent, 'created' => false];
        }

        $agent = $assignTo ?? $this->assigner->next();
        $id = (string) Str::uuid();
        $now = now()->toIso8601String();
        DB::table('prospects')->insert([
            ...$row, 'id' => $id, 'agent' => $agent, 'source' => $source, 'status' => 'new', 'captured_by' => $capturedBy,
            'assigned_at' => $agent ? $now : null, 'created' => $now, 'updated' => $now,
        ]);

        return ['id' => $id, 'duplicate' => null, 'agent' => $agent, 'created' => true];
    }
}
