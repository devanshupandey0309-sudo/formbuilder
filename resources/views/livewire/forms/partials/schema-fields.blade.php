@foreach ($schema['sections'] ?? [] as $sectionIndex => $section)
    <section @class(['space-y-5', 'pb-8 border-b border-gray-200' => ! $loop->last]) aria-labelledby="section-{{ $sectionIndex }}">
        <div>
            <h2 id="section-{{ $sectionIndex }}" class="text-lg font-medium text-gray-900">{{ $section['title'] }}</h2>
            @if (! empty($section['description']))
                <p class="text-sm text-gray-600 mt-1">{{ $section['description'] }}</p>
            @endif
        </div>

        @foreach ($section['fields'] ?? [] as $field)
            @php
                $key = $field['key'];
                $type = $field['type'];
                $required = (bool) ($field['required'] ?? false);
                $placeholder = $field['config']['placeholder'] ?? '';
                $options = $field['config']['options'] ?? [];
                $errorKey = 'answers.'.$key;
                $hasError = $errors->has($errorKey);
            @endphp

            @if ($type === 'checkbox' && empty($options))
                <div>
                    <label class="inline-flex items-start gap-3 text-sm text-gray-700 cursor-pointer" for="field-{{ $key }}">
                        <input type="checkbox" id="field-{{ $key }}" wire:model="answers.{{ $key }}"
                            @if ($required) required aria-required="true" @endif
                            @if ($hasError) aria-invalid="true" aria-describedby="error-{{ $key }}" @endif
                            class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>
                            {{ $field['label'] }}
                            @if ($required)
                                <span class="text-red-500" aria-hidden="true">*</span>
                                <span class="sr-only">(required)</span>
                            @endif
                        </span>
                    </label>
                    @error($errorKey)
                        <p id="error-{{ $key }}" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @else
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="field-{{ $key }}">
                        {{ $field['label'] }}
                        @if ($required)
                            <span class="text-red-500" aria-hidden="true">*</span>
                            <span class="sr-only">(required)</span>
                        @endif
                    </label>

                    @switch($type)
                        @case('textarea')
                            <textarea id="field-{{ $key }}" wire:model="answers.{{ $key }}" rows="4"
                                @if ($required) required aria-required="true" @endif
                                @if ($hasError) aria-invalid="true" aria-describedby="error-{{ $key }}" @endif
                                @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                            @break

                        @case('select')
                            <select id="field-{{ $key }}" wire:model="answers.{{ $key }}"
                                @if ($required) required aria-required="true" @endif
                                @if ($hasError) aria-invalid="true" aria-describedby="error-{{ $key }}" @endif
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
                            <fieldset class="mt-2 space-y-2" @if ($hasError) aria-describedby="error-{{ $key }}" @endif>
                                <legend class="sr-only">{{ $field['label'] }}</legend>
                                @foreach ($options as $optionIndex => $option)
                                    @php
                                        $value = is_array($option) ? ($option['value'] ?? $option['label']) : $option;
                                        $label = is_array($option) ? ($option['label'] ?? $value) : $option;
                                    @endphp
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="radio" id="field-{{ $key }}-{{ $optionIndex }}" wire:model="answers.{{ $key }}" value="{{ $value }}"
                                            @if ($required) required aria-required="true" @endif
                                            @if ($hasError) aria-invalid="true" @endif
                                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </fieldset>
                            @break

                        @case('checkbox')
                            <fieldset class="mt-2 space-y-2" @if ($hasError) aria-describedby="error-{{ $key }}" @endif>
                                <legend class="sr-only">{{ $field['label'] }}</legend>
                                @foreach ($options as $optionIndex => $option)
                                    @php
                                        $value = is_array($option) ? ($option['value'] ?? $option['label']) : $option;
                                        $label = is_array($option) ? ($option['label'] ?? $value) : $option;
                                    @endphp
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" id="field-{{ $key }}-{{ $optionIndex }}" wire:model="answers.{{ $key }}" value="{{ $value }}"
                                            @if ($hasError) aria-invalid="true" @endif
                                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </fieldset>
                            @break

                        @default
                            <input id="field-{{ $key }}" type="{{ $type }}" wire:model="answers.{{ $key }}"
                                @if ($required) required aria-required="true" @endif
                                @if ($hasError) aria-invalid="true" aria-describedby="error-{{ $key }}" @endif
                                @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    @endswitch

                    @error($errorKey)
                        <p id="error-{{ $key }}" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        @endforeach
    </section>
@endforeach
