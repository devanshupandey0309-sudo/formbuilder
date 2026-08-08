@props(['form', 'active' => null])

<div class="flex flex-wrap items-center gap-2">
    <a href="{{ route('forms.builder', $form) }}" wire:navigate
        @class([
            'inline-flex items-center px-3 py-2 rounded-md text-sm font-medium',
            'bg-indigo-600 text-white' => $active === 'builder',
            'border border-gray-300 text-gray-700 bg-white hover:bg-gray-50' => $active !== 'builder',
        ])>
        Builder
    </a>
    <a href="{{ route('forms.preview', $form) }}" wire:navigate
        @class([
            'inline-flex items-center px-3 py-2 rounded-md text-sm font-medium',
            'bg-indigo-600 text-white' => $active === 'preview',
            'border border-gray-300 text-gray-700 bg-white hover:bg-gray-50' => $active !== 'preview',
        ])>
        Preview
    </a>
    <a href="{{ route('forms.insights', $form) }}" wire:navigate
        @class([
            'inline-flex items-center px-3 py-2 rounded-md text-sm font-medium',
            'bg-indigo-600 text-white' => $active === 'insights',
            'border border-gray-300 text-gray-700 bg-white hover:bg-gray-50' => $active !== 'insights',
        ])>
        Insights
    </a>
</div>
