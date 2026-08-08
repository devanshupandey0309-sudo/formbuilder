@foreach ($schema['sections'] ?? [] as $section)
    <div class="space-y-4">
        <h2 class="text-lg font-medium text-gray-900">{{ $section['title'] }}</h2>

        @foreach ($section['fields'] ?? [] as $field)
            @php
                $key = $field['key'];
                $type = $field['type'];
                $required = (bool) ($field['required'] ?? false);
                $placeholder = $field['config']['placeholder'] ?? '';
                $options = $field['config']['options'] ?? [];
                $errorKey = 'answers.'.$key;
            @endphp

            <div>
                <label class="block text-sm font-medium text-gray-700" for="field-{{ $key }}">
                    {{ $field['label'] }}
                    @if ($required)
                        <span class="text-red-500">*</span>
                    @endif
                </label>

                @switch($type)
                    @case('textarea')
                        <textarea id="field-{{ $key }}" wire:model="answers.{{ $key }}" rows="4"
                            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                        @break

                    @case('select')
                        <select id="field-{{ $key }}" wire:model="answers.{{ $key }}"
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">Select...</option>
                            @foreach ($options as $option)
                                @php
                                    $value = is_array($option) ? ($option['value'] ?? $option['label']) : $option;
                                    $label = is_array($option) ? ($option['label'] ?? $value) : $option;
                                @endphp
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @break

                    @case('radio')
                        <div class="mt-2 space-y-2">
                            @foreach ($options as $option)
                                @php
                                    $value = is_array($option) ? ($option['value'] ?? $option['label']) : $option;
                                    $label = is_array($option) ? ($option['label'] ?? $value) : $option;
                                @endphp
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="radio" wire:model="answers.{{ $key }}" value="{{ $value }}"
                                        class="border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @break

                    @case('checkbox')
                        <div class="mt-2 space-y-2">
                            @foreach ($options as $option)
                                @php
                                    $value = is_array($option) ? ($option['value'] ?? $option['label']) : $option;
                                    $label = is_array($option) ? ($option['label'] ?? $value) : $option;
                                @endphp
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model="answers.{{ $key }}" value="{{ $value }}"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @break

                    @default
                        <input id="field-{{ $key }}" type="{{ $type }}" wire:model="answers.{{ $key }}"
                            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                @endswitch

                @error($errorKey)
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>
@endforeach
