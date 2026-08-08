<div class="py-8">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">{{ $schema['title'] ?? $form->title }}</h1>
                @if (! empty($schema['description']))
                    <p class="text-sm text-gray-600 mt-1">{{ $schema['description'] }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('livewire.forms.partials.form-nav', ['form' => $form, 'active' => 'preview'])
            </div>
        </div>

        @if ($isDraftPreview)
            <div class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                Draft preview — submissions are disabled until the form is published.
            </div>
        @endif

        @if ($statusMessage)
            <div @class([
                'mb-4 rounded-md p-3 text-sm',
                'bg-green-50 text-green-800' => $statusType === 'success',
                'bg-red-50 text-red-800' => $statusType === 'error',
            ])>
                {{ $statusMessage }}
            </div>
        @endif

        <form wire:submit="submit" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
            @include('livewire.forms.partials.schema-fields', ['schema' => $schema, 'answers' => $answers])

            <x-primary-button type="submit">{{ $isDraftPreview ? 'Preview Submit (disabled)' : 'Submit' }}</x-primary-button>
        </form>
    </div>
</div>
