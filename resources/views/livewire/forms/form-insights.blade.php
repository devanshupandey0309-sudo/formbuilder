@php
    $overview = $insights['overview'] ?? [];
    $trend = $insights['trend'] ?? [];
    $fields = $insights['fields'] ?? [];
    $recommendations = $insights['insights'] ?? [];
    $maxTrendCount = max(array_column($trend, 'count') ?: [0]);
@endphp

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">{{ $form->title }}</h1>
                    <p class="mt-1 text-sm text-gray-600">Submission insights and field-level analytics.</p>
                </div>
                @include('livewire.forms.partials.form-nav', ['form' => $form, 'active' => 'insights'])
            </div>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Total Submissions</p>
                <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $overview['total_submissions'] ?? 0 }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Today</p>
                <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $overview['today'] ?? 0 }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Last 7 Days</p>
                <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $overview['last_7_days'] ?? 0 }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Last 30 Days</p>
                <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $overview['last_30_days'] ?? 0 }}</p>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Submission Trend</h2>
                    <p class="text-sm text-gray-600">Daily submissions over the last 30 days.</p>
                </div>
                <p class="text-sm text-gray-500">
                    Avg {{ number_format($overview['average_per_day'] ?? 0, 2) }} / day
                </p>
            </div>

            <div class="mt-6 overflow-x-auto">
                <div class="flex items-end gap-1 min-w-[720px] h-40">
                    @foreach ($trend as $point)
                        @php
                            $height = $maxTrendCount > 0
                                ? max(4, (int) round(($point['count'] / $maxTrendCount) * 100))
                                : 4;
                        @endphp
                        <div class="flex-1 flex flex-col items-center justify-end gap-2">
                            <span class="text-[10px] text-gray-500">{{ $point['count'] }}</span>
                            <div class="w-full rounded-t bg-indigo-500" style="height: {{ $height }}%"></div>
                            <span class="text-[10px] text-gray-400 rotate-45 origin-top-left whitespace-nowrap">
                                {{ \Illuminate\Support\Carbon::parse($point['date'])->format('M j') }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-900">Field Insights</h2>

            @if ($fields === [])
                <p class="mt-4 text-sm text-gray-500">No fields available for analysis.</p>
            @else
                <div class="mt-6 space-y-6">
                    @foreach ($fields as $field)
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <div>
                                    <h3 class="text-base font-medium text-gray-900">{{ $field['field_label'] }}</h3>
                                    <p class="text-xs text-gray-500">{{ $field['field_type'] }} · {{ $field['field_key'] }}</p>
                                </div>
                                <p class="text-sm text-gray-700">
                                    Response rate:
                                    <span class="font-semibold">{{ number_format($field['response_rate'], 1) }}%</span>
                                    ({{ $field['total_responses'] }} responses)
                                </p>
                            </div>

                            @if (! empty($field['distribution']))
                                <div class="mt-4 space-y-2">
                                    @foreach ($field['distribution'] as $option)
                                        <div>
                                            <div class="flex items-center justify-between text-sm text-gray-700">
                                                <span>{{ $option['option'] }}</span>
                                                <span>{{ number_format($option['percentage'], 1) }}%</span>
                                            </div>
                                            <div class="mt-1 h-2 rounded-full bg-gray-100">
                                                <div class="h-2 rounded-full bg-indigo-500"
                                                    style="width: {{ min(100, $option['percentage']) }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if (! empty($field['numeric_summary']))
                                <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                                    <div class="rounded-md bg-gray-50 p-3">
                                        <p class="text-gray-500">Min</p>
                                        <p class="font-medium text-gray-900">{{ $field['numeric_summary']['min'] ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-md bg-gray-50 p-3">
                                        <p class="text-gray-500">Max</p>
                                        <p class="font-medium text-gray-900">{{ $field['numeric_summary']['max'] ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-md bg-gray-50 p-3">
                                        <p class="text-gray-500">Average</p>
                                        <p class="font-medium text-gray-900">{{ $field['numeric_summary']['average'] ?? '—' }}</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-900">Recommendations</h2>

            @if ($recommendations === [])
                <p class="mt-4 text-sm text-gray-500">No recommendations yet.</p>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($recommendations as $item)
                        <li @class([
                            'text-sm flex items-start gap-2',
                            'text-green-700' => ($item['severity'] ?? '') === 'success',
                            'text-yellow-800' => ($item['severity'] ?? '') === 'warning',
                            'text-gray-700' => ($item['severity'] ?? '') === 'info',
                        ])>
                            <span aria-hidden="true">
                                @if (($item['severity'] ?? '') === 'success')
                                    ✓
                                @elseif (($item['severity'] ?? '') === 'warning')
                                    ⚠
                                @else
                                    💡
                                @endif
                            </span>
                            <span>{{ $item['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
