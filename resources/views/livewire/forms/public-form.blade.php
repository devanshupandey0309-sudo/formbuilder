<div class="py-8 sm:py-12">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        @if ($submitted)
            <div class="bg-white shadow-sm sm:rounded-lg p-8 text-center" role="status" aria-live="polite">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                    <svg class="h-6 w-6 text-green-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <h1 class="mt-4 text-2xl font-semibold text-gray-900">Submission received</h1>
                <p class="mt-2 text-sm text-gray-600">{{ $statusMessage }}</p>
            </div>
        @else
            <div class="mb-6">
                <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900">{{ $schema['title'] ?? $form->title }}</h1>
                @if (! empty($schema['description']))
                    <p class="text-sm sm:text-base text-gray-600 mt-2">{{ $schema['description'] }}</p>
                @endif
            </div>

            @if ($statusMessage && $statusType === 'error')
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    {{ $statusMessage }}
                </div>
            @endif

            <form wire:submit="submit" class="bg-white shadow-sm sm:rounded-lg p-6 sm:p-8 space-y-8" wire:loading.class="opacity-75">
                @include('livewire.forms.partials.schema-fields', ['schema' => $schema, 'answers' => $answers])

                <div class="pt-2 border-t border-gray-100">
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">Submit</span>
                        <span wire:loading wire:target="submit">Submitting...</span>
                    </x-primary-button>
                </div>
            </form>
        @endif
    </div>
</div>
