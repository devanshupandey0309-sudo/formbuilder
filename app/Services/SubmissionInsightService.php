<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use Carbon\Carbon;

class SubmissionInsightService
{
    private const TREND_DAYS = 30;

    private const LOW_RESPONSE_RATE_THRESHOLD = 75.0;

    private const HIGH_CONCENTRATION_THRESHOLD = 60.0;

    private const HIGH_REQUIRED_COMPLETION_THRESHOLD = 90.0;

    /** @var list<string> */
    private const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    public function __construct(
        private readonly FormService $formService,
    ) {}

    /**
     * @return array{
     *     overview: array<string, mixed>,
     *     trend: list<array{date: string, count: int}>,
     *     fields: list<array<string, mixed>>,
     *     insights: list<array{severity: string, code: string, message: string}>
     * }
     */
    public function getInsights(Form $form): array
    {
        $overview = $this->buildOverview($form);
        $trend = $this->buildTrend($form);
        $fields = $this->buildFieldInsights($form, (int) $overview['total_submissions']);
        $insights = $this->buildRecommendations($overview, $fields);

        return [
            'overview' => $overview,
            'trend' => $trend,
            'fields' => $fields,
            'insights' => $insights,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(Form $form): array
    {
        $today = now()->startOfDay();
        $sevenDaysAgo = now()->subDays(6)->startOfDay();
        $thirtyDaysAgo = now()->subDays(29)->startOfDay();

        $stats = Submission::query()
            ->where('form_id', $form->id)
            ->selectRaw('COUNT(*) as total_submissions')
            ->selectRaw(
                'SUM(CASE WHEN submitted_at >= ? THEN 1 ELSE 0 END) as today',
                [$today],
            )
            ->selectRaw(
                'SUM(CASE WHEN submitted_at >= ? THEN 1 ELSE 0 END) as last_7_days',
                [$sevenDaysAgo],
            )
            ->selectRaw(
                'SUM(CASE WHEN submitted_at >= ? THEN 1 ELSE 0 END) as last_30_days',
                [$thirtyDaysAgo],
            )
            ->selectRaw('MIN(submitted_at) as first_submission_at')
            ->selectRaw('MAX(submitted_at) as latest_submission_at')
            ->first();

        $total = (int) ($stats->total_submissions ?? 0);

        $firstAt = $stats->first_submission_at
            ? Carbon::parse($stats->first_submission_at)
            : null;
        $latestAt = $stats->latest_submission_at
            ? Carbon::parse($stats->latest_submission_at)
            : null;

        $averagePerDay = 0.0;

        if ($total > 0 && $firstAt !== null && $latestAt !== null) {
            $daySpan = max($firstAt->startOfDay()->diffInDays($latestAt->startOfDay()) + 1, 1);
            $averagePerDay = round($total / $daySpan, 2);
        }

        return [
            'total_submissions' => $total,
            'today' => (int) ($stats->today ?? 0),
            'last_7_days' => (int) ($stats->last_7_days ?? 0),
            'last_30_days' => (int) ($stats->last_30_days ?? 0),
            'average_per_day' => $averagePerDay,
            'first_submission_at' => $firstAt?->toIso8601String(),
            'latest_submission_at' => $latestAt?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function buildTrend(Form $form): array
    {
        $startDate = now()->subDays(self::TREND_DAYS - 1)->startOfDay();

        $counts = Submission::query()
            ->where('form_id', $form->id)
            ->where('submitted_at', '>=', $startDate)
            ->selectRaw('DATE(submitted_at) as trend_date')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('trend_date')
            ->orderBy('trend_date')
            ->pluck('count', 'trend_date');

        $trend = [];

        for ($day = 0; $day < self::TREND_DAYS; $day++) {
            $date = $startDate->copy()->addDays($day)->toDateString();
            $trend[] = [
                'date' => $date,
                'count' => (int) ($counts[$date] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFieldInsights(Form $form, int $totalSubmissions): array
    {
        $compiled = $this->formService->compileSchema($form);
        $responseCounts = $this->responseCountsByFieldKey($form->id);

        $selectRadioKeys = [];
        /** @var array<string, array<string, mixed>> $checkboxFields */
        $checkboxFields = [];
        $numberKeys = [];

        foreach ($compiled['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $type = $field['type'];

                if (in_array($type, ['select', 'radio'], true)) {
                    $selectRadioKeys[] = $field['key'];
                } elseif ($type === 'checkbox') {
                    $checkboxFields[$field['key']] = $field;
                } elseif ($type === 'number') {
                    $numberKeys[] = $field['key'];
                }
            }
        }

        $optionDistributions = $this->optionDistributionsByFieldKeys($form->id, $selectRadioKeys);
        $checkboxDistributions = $this->checkboxDistributionsByFieldKeys(
            $form->id,
            $checkboxFields,
            $totalSubmissions,
        );
        $numericSummaries = $this->numericSummariesByFieldKeys($form->id, $numberKeys);

        $fieldInsights = [];

        foreach ($compiled['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $key = $field['key'];
                $responses = (int) ($responseCounts[$key] ?? 0);
                $responseRate = $totalSubmissions > 0
                    ? round(($responses / $totalSubmissions) * 100, 1)
                    : 0.0;

                $insight = [
                    'field_key' => $key,
                    'field_label' => $field['label'],
                    'field_type' => $field['type'],
                    'required' => (bool) ($field['required'] ?? false),
                    'total_responses' => $responses,
                    'response_rate' => $responseRate,
                ];

                if (in_array($field['type'], ['select', 'radio'], true)) {
                    $insight['distribution'] = $this->formatOptionDistribution(
                        $optionDistributions[$key] ?? [],
                        $responses,
                    );
                }

                if ($field['type'] === 'checkbox') {
                    $insight['distribution'] = $checkboxDistributions[$key] ?? [];
                }

                if ($field['type'] === 'number') {
                    $insight['numeric_summary'] = $numericSummaries[$key] ?? [
                        'min' => null,
                        'max' => null,
                        'average' => null,
                    ];
                }

                $fieldInsights[] = $insight;
            }
        }

        return $fieldInsights;
    }

    /**
     * @return array<string, int>
     */
    private function responseCountsByFieldKey(int $formId): array
    {
        return SubmissionAnswer::query()
            ->join('submissions', 'submissions.id', '=', 'submission_answers.submission_id')
            ->where('submissions.form_id', $formId)
            ->where(function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNotNull('submission_answers.value_text')
                        ->where('submission_answers.value_text', '!=', '');
                })->orWhereNotNull('submission_answers.value_json');
            })
            ->where(function ($query) {
                $query->whereNull('submission_answers.value_json')
                    ->orWhereRaw('JSON_LENGTH(submission_answers.value_json) > 0');
            })
            ->selectRaw('submission_answers.field_key')
            ->selectRaw('COUNT(DISTINCT submission_answers.submission_id) as response_count')
            ->groupBy('submission_answers.field_key')
            ->pluck('response_count', 'field_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  list<string>  $fieldKeys
     * @return array<string, list<array{option: string, count: int}>>
     */
    private function optionDistributionsByFieldKeys(int $formId, array $fieldKeys): array
    {
        if ($fieldKeys === []) {
            return [];
        }

        $rows = SubmissionAnswer::query()
            ->join('submissions', 'submissions.id', '=', 'submission_answers.submission_id')
            ->where('submissions.form_id', $formId)
            ->whereIn('submission_answers.field_key', $fieldKeys)
            ->whereNotNull('submission_answers.value_text')
            ->where('submission_answers.value_text', '!=', '')
            ->selectRaw('submission_answers.field_key')
            ->selectRaw('submission_answers.value_text as option_value')
            ->selectRaw('COUNT(*) as option_count')
            ->groupBy('submission_answers.field_key', 'submission_answers.value_text')
            ->orderBy('submission_answers.field_key')
            ->orderByDesc('option_count')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->field_key][] = [
                'option' => (string) $row->option_value,
                'count' => (int) $row->option_count,
            ];
        }

        return $grouped;
    }

    /**
     * @param  list<array{option: string, count: int}>  $rows
     * @return list<array{option: string, count: int, percentage: float}>
     */
    private function formatOptionDistribution(array $rows, int $totalResponses): array
    {
        if ($totalResponses === 0) {
            return [];
        }

        return collect($rows)->map(fn (array $row) => [
            'option' => $row['option'],
            'count' => $row['count'],
            'percentage' => round(($row['count'] / $totalResponses) * 100, 1),
        ])->values()->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $checkboxFields
     * @return array<string, list<array{option: string, count: int, percentage: float}>>
     */
    private function checkboxDistributionsByFieldKeys(
        int $formId,
        array $checkboxFields,
        int $totalSubmissions,
    ): array {
        if ($checkboxFields === []) {
            return [];
        }

        $optionCounts = [];

        foreach ($checkboxFields as $fieldKey => $field) {
            $options = $this->fieldOptionValues($field);

            if ($options === []) {
                continue;
            }

            foreach ($options as $option) {
                $optionCounts[$fieldKey][$option] = 0;
            }
        }

        $relevantKeys = array_keys($optionCounts);

        if ($relevantKeys === []) {
            return [];
        }

        $answers = SubmissionAnswer::query()
            ->join('submissions', 'submissions.id', '=', 'submission_answers.submission_id')
            ->where('submissions.form_id', $formId)
            ->whereIn('submission_answers.field_key', $relevantKeys)
            ->whereNotNull('submission_answers.value_json')
            ->get(['submission_answers.field_key', 'submission_answers.value_json']);

        foreach ($answers as $answer) {
            $fieldKey = (string) $answer->field_key;
            $values = is_array($answer->value_json) ? $answer->value_json : [$answer->value_json];

            foreach ($values as $value) {
                $option = (string) $value;

                if (isset($optionCounts[$fieldKey][$option])) {
                    $optionCounts[$fieldKey][$option]++;
                }
            }
        }

        $distributions = [];

        foreach ($optionCounts as $fieldKey => $counts) {
            $distribution = [];

            foreach ($counts as $option => $count) {
                $distribution[] = [
                    'option' => $option,
                    'count' => $count,
                    'percentage' => $totalSubmissions > 0
                        ? round(($count / $totalSubmissions) * 100, 1)
                        : 0.0,
                ];
            }

            usort($distribution, fn (array $a, array $b) => $b['count'] <=> $a['count']);
            $distributions[$fieldKey] = $distribution;
        }

        return $distributions;
    }

    /**
     * @param  list<string>  $fieldKeys
     * @return array<string, array{min: float|null, max: float|null, average: float|null}>
     */
    private function numericSummariesByFieldKeys(int $formId, array $fieldKeys): array
    {
        if ($fieldKeys === []) {
            return [];
        }

        $rows = SubmissionAnswer::query()
            ->join('submissions', 'submissions.id', '=', 'submission_answers.submission_id')
            ->where('submissions.form_id', $formId)
            ->whereIn('submission_answers.field_key', $fieldKeys)
            ->whereNotNull('submission_answers.value_text')
            ->where('submission_answers.value_text', '!=', '')
            ->selectRaw('submission_answers.field_key')
            ->selectRaw('MIN(CAST(submission_answers.value_text AS DECIMAL(20, 4))) as min_value')
            ->selectRaw('MAX(CAST(submission_answers.value_text AS DECIMAL(20, 4))) as max_value')
            ->selectRaw('AVG(CAST(submission_answers.value_text AS DECIMAL(20, 4))) as average_value')
            ->groupBy('submission_answers.field_key')
            ->get();

        $summaries = [];

        foreach ($rows as $row) {
            if ($row->min_value === null) {
                $summaries[(string) $row->field_key] = [
                    'min' => null,
                    'max' => null,
                    'average' => null,
                ];

                continue;
            }

            $summaries[(string) $row->field_key] = [
                'min' => (float) $row->min_value,
                'max' => (float) $row->max_value,
                'average' => round((float) $row->average_value, 2),
            ];
        }

        return $summaries;
    }

    /**
     * @return list<array{option: string, count: int, percentage: float}>
     */
    private function optionDistribution(int $formId, string $fieldKey, int $totalResponses): array
    {
        return $this->formatOptionDistribution(
            $this->optionDistributionsByFieldKeys($formId, [$fieldKey])[$fieldKey] ?? [],
            $totalResponses,
        );
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array{option: string, count: int, percentage: float}>
     */
    private function checkboxDistribution(
        int $formId,
        string $fieldKey,
        array $field,
        int $totalSubmissions,
    ): array {
        return $this->checkboxDistributionsByFieldKeys(
            $formId,
            [$fieldKey => $field],
            $totalSubmissions,
        )[$fieldKey] ?? [];
    }

    /**
     * @return array{min: float|null, max: float|null, average: float|null}
     */
    private function numericSummary(int $formId, string $fieldKey): array
    {
        return $this->numericSummariesByFieldKeys($formId, [$fieldKey])[$fieldKey] ?? [
            'min' => null,
            'max' => null,
            'average' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{severity: string, code: string, message: string}>
     */
    private function buildRecommendations(array $overview, array $fields): array
    {
        $insights = [];

        if ((int) $overview['total_submissions'] === 0) {
            $insights[] = [
                'severity' => 'info',
                'code' => 'no_submissions',
                'message' => 'No submissions yet. Share your public form to start collecting responses.',
            ];

            return $insights;
        }

        $requiredFields = collect($fields)->where('required', true);
        $optionalFields = collect($fields)->where('required', false);

        if ($requiredFields->isNotEmpty()) {
            $averageRequiredRate = round($requiredFields->avg('response_rate'), 1);

            if ($averageRequiredRate >= self::HIGH_REQUIRED_COMPLETION_THRESHOLD) {
                $insights[] = [
                    'severity' => 'success',
                    'code' => 'high_required_completion',
                    'message' => 'Most respondents completed all required information.',
                ];
            }
        }

        foreach ($fields as $field) {
            if ($field['response_rate'] < self::LOW_RESPONSE_RATE_THRESHOLD) {
                $label = $field['field_label'] ?: $field['field_key'];
                $insights[] = [
                    'severity' => 'warning',
                    'code' => 'low_response_rate',
                    'message' => sprintf(
                        '%s has a low response rate (%s%%). Consider reviewing whether this field should be required.',
                        $label,
                        number_format($field['response_rate'], 1),
                    ),
                ];
            }

            if (! empty($field['distribution'])) {
                $top = $field['distribution'][0];

                if (($top['percentage'] ?? 0) >= self::HIGH_CONCENTRATION_THRESHOLD) {
                    $label = $field['field_label'] ?: $field['field_key'];
                    $insights[] = [
                        'severity' => 'info',
                        'code' => 'concentrated_option',
                        'message' => sprintf(
                            '%s accounts for %s%% of %s responses.',
                            $top['option'],
                            number_format($top['percentage'], 1),
                            $label,
                        ),
                    ];
                }
            }
        }

        $lowOptionalFields = $optionalFields
            ->filter(fn (array $field) => $field['response_rate'] > 0 && $field['response_rate'] < 40)
            ->count();

        if ($lowOptionalFields >= 2) {
            $insights[] = [
                'severity' => 'info',
                'code' => 'many_unanswered_optional_fields',
                'message' => 'Several optional fields have low response rates. Consider removing or simplifying them.',
            ];
        }

        return $this->uniqueInsights($insights);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function fieldOptionValues(array $field): array
    {
        $options = $field['config']['options'] ?? [];

        return collect($options)->map(function ($option) {
            if (is_array($option)) {
                return (string) ($option['label'] ?? $option['value'] ?? '');
            }

            return (string) $option;
        })->filter()->values()->all();
    }

    /**
     * @param  list<array{severity: string, code: string, message: string}>  $insights
     * @return list<array{severity: string, code: string, message: string}>
     */
    private function uniqueInsights(array $insights): array
    {
        return collect($insights)
            ->unique(fn (array $insight) => $insight['code'].'|'.$insight['message'])
            ->values()
            ->all();
    }
}
