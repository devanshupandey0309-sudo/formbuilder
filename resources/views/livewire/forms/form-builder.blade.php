@php
    $optionTypes = ['select', 'radio', 'checkbox'];
    $numberType = 'number';
@endphp

<div class="py-6" wire:loading.class="opacity-75"
    data-form-builder-root
    data-form-id="{{ $form->id }}"
    data-draft-revision="{{ $draftRevision }}"
    data-draft-saved-at="{{ $lastSavedAt }}">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if ($recoveryOffer)
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <p class="text-sm text-amber-900">
                    Unsaved changes from
                    {{ \Illuminate\Support\Carbon::parse($recoveryOffer['timestamp'])->format('M j, g:i A') }}
                    were found.
                </p>
                <div class="flex gap-2">
                    <button type="button" wire:click="restoreRecovery"
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium text-white bg-amber-600 hover:bg-amber-700">
                        Restore
                    </button>
                    <button type="button" wire:click="discardRecovery"
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium text-amber-900 bg-white border border-amber-300 hover:bg-amber-100">
                        Discard
                    </button>
                </div>
            </div>
        @endif

        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex-1">
                    <label for="form-title" class="sr-only">Form title</label>
                    <input id="form-title" type="text" wire:model.live.debounce.1500ms="formTitle"
                        class="w-full text-2xl font-semibold border-0 border-b border-transparent focus:border-indigo-500 focus:ring-0 px-0"
                        placeholder="Form title" />
                    <textarea wire:model.live.debounce.1500ms="formDescription" rows="2" placeholder="Form description (optional)"
                        class="mt-2 w-full text-sm text-gray-600 border-gray-200 rounded-md focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    @include('livewire.forms.partials.form-nav', ['form' => $form, 'active' => 'builder'])

                    <span @class([
                        'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium',
                        'bg-gray-100 text-gray-700' => $autosaveStatus === 'saved',
                        'bg-blue-100 text-blue-800' => $autosaveStatus === 'saving',
                        'bg-yellow-100 text-yellow-800' => in_array($autosaveStatus, ['dirty', 'unsaved'], true),
                        'bg-red-100 text-red-800' => in_array($autosaveStatus, ['failed', 'conflict'], true),
                    ])>
                        @switch($autosaveStatus)
                            @case('saving')
                                Saving...
                                @break
                            @case('dirty')
                            @case('unsaved')
                                Unsaved changes
                                @break
                            @case('failed')
                                Save failed — retry
                                @break
                            @case('conflict')
                                Newer draft on server
                                @break
                            @default
                                @if ($lastSavedAt)
                                    Saved {{ \Illuminate\Support\Carbon::parse($lastSavedAt)->diffForHumans() }}
                                @else
                                    All changes saved
                                @endif
                        @endswitch
                    </span>

                    @if ($autosaveStatus === 'conflict')
                        <button type="button" wire:click="reloadFromServer" wire:loading.attr="disabled" wire:target="reloadFromServer"
                            class="inline-flex items-center px-3 py-2 border border-amber-300 rounded-md text-sm font-medium text-amber-900 bg-white hover:bg-amber-50 disabled:opacity-50">
                            <span wire:loading wire:target="reloadFromServer">Reloading...</span>
                            <span wire:loading.remove wire:target="reloadFromServer">Reload from server</span>
                        </button>
                    @endif

                    <button type="button" wire:click="saveDraft" wire:loading.attr="disabled"
                        wire:target="saveDraft,autosaveDraft"
                        class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50">
                        <span wire:loading wire:target="saveDraft,autosaveDraft">Saving...</span>
                        <span wire:loading.remove wire:target="saveDraft,autosaveDraft">Save Draft</span>
                    </button>
                    <span @class([
                        'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium',
                        'bg-green-100 text-green-800' => $form->status === 'published' && ! $needsRepublish,
                        'bg-yellow-100 text-yellow-800' => $form->status === 'draft',
                        'bg-amber-100 text-amber-800' => $needsRepublish,
                    ])>
                        @if ($needsRepublish)
                            Published — unsaved changes
                        @else
                            {{ ucfirst($form->status) }}
                        @endif
                    </span>

                    @if ($form->status === 'published')
                        <button type="button" wire:click="unpublish" wire:loading.attr="disabled" wire:target="unpublish"
                            wire:confirm="Unpublish this form? The public URL will stop accepting submissions until you publish again."
                            class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50">
                            <span wire:loading wire:target="unpublish">Unpublishing...</span>
                            <span wire:loading.remove wire:target="unpublish">Unpublish</span>
                        </button>
                    @endif

                    <button type="button" wire:click="publish" wire:loading.attr="disabled" wire:target="publish"
                        class="inline-flex items-center px-4 py-2 border border-transparent rounded-md text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50">
                        <span wire:loading wire:target="publish">Publishing...</span>
                        <span wire:loading.remove wire:target="publish">Publish</span>
                    </button>
                </div>
            </div>

            @if ($needsRepublish)
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                    <p class="font-medium">Draft changes are not live yet.</p>
                    <p class="mt-1">The public form still serves the last published version. Publish again to update what respondents see.</p>
                </div>
            @elseif ($form->status === 'draft')
                <p class="mt-4 text-sm text-gray-600">
                    Publishing compiles the current builder structure and makes the form available at a public fill URL.
                </p>
            @endif

            @if ($statusMessage)
                <div @class([
                    'mt-4 rounded-md p-3 text-sm',
                    'bg-green-50 text-green-800' => $statusType === 'success',
                    'bg-red-50 text-red-800' => $statusType === 'error',
                ]) role="alert" aria-live="polite">
                    {{ $statusMessage }}
                </div>
            @endif

            @if ($form->status === 'published' && $form->slug)
                <div class="mt-4 rounded-md border border-green-200 bg-green-50 p-4">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-green-900">Public fill URL</p>
                            <p class="text-xs text-green-800 mt-1">Share this link so anyone can fill and submit the published form.</p>
                            <a href="{{ route('forms.public', $form->slug) }}" target="_blank" rel="noopener noreferrer"
                                class="mt-2 inline-block text-sm font-medium text-indigo-700 hover:text-indigo-900 break-all">
                                {{ route('forms.public', $form->slug) }}
                            </a>
                        </div>
                        <a href="{{ route('forms.public', $form->slug) }}" target="_blank" rel="noopener noreferrer"
                            class="inline-flex shrink-0 items-center justify-center px-4 py-2 rounded-md text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                            Open public form
                        </a>
                    </div>
                </div>
            @endif
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6"
            @if ($activeAiJobId && in_array($aiJobStatus, ['pending', 'processing'], true)) wire:poll.2s="refreshAiJob" @endif>
            <h2 class="text-lg font-semibold text-gray-900">AI Form Assistant</h2>
            <p class="text-sm text-gray-600 mt-1">Describe a new form or edits to the current form. Changes are proposed first and must be applied explicitly.</p>

            <div class="mt-4 space-y-3">
                <textarea wire:model="aiPrompt" rows="3" placeholder="Describe what you want..."
                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>

                <div class="flex flex-wrap gap-2">
                    <x-primary-button type="button" wire:click="startAiGenerate" wire:loading.attr="disabled" wire:target="startAiGenerate">
                        <span wire:loading wire:target="startAiGenerate">Starting...</span>
                        <span wire:loading.remove wire:target="startAiGenerate">Generate</span>
                    </x-primary-button>
                    <button type="button" wire:click="startAiEdit" wire:loading.attr="disabled" wire:target="startAiEdit"
                        class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50">
                        <span wire:loading wire:target="startAiEdit">Starting...</span>
                        <span wire:loading.remove wire:target="startAiEdit">Edit Current Form</span>
                    </button>
                    @if ($activeAiJobId)
                        <button type="button" wire:click="discardAiJob"
                            class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Discard
                        </button>
                    @endif
                </div>

                @if ($activeAiJobId)
                    <div class="rounded-md border border-gray-200 p-4 space-y-3">
                        <p class="text-sm text-gray-700">
                            Status:
                            <span @class([
                                'font-medium',
                                'text-yellow-700' => in_array($aiJobStatus, ['pending', 'processing'], true),
                                'text-green-700' => $aiJobStatus === 'completed',
                                'text-red-700' => $aiJobStatus === 'failed',
                            ])>{{ ucfirst($aiJobStatus ?? 'unknown') }}</span>
                            @if ($aiJobType)
                                <span class="text-gray-500">({{ $aiJobType }})</span>
                            @endif
                        </p>

                        @if ($aiJobError)
                            <p class="text-sm text-red-700">{{ $aiJobError }}</p>
                        @endif

                        @if ($aiJobStatus === 'completed' && $aiProposedJson)
                            <div>
                                <p class="text-sm font-medium text-gray-900 mb-2">AI proposed changes</p>
                                <pre class="text-xs bg-gray-50 border border-gray-200 rounded-md p-3 overflow-x-auto">{{ $aiProposedJson }}</pre>
                            </div>

                            <div class="flex gap-2">
                                <x-primary-button type="button" wire:click="applyAiJob" wire:loading.attr="disabled" wire:target="applyAiJob">
                                    <span wire:loading wire:target="applyAiJob">Applying...</span>
                                    <span wire:loading.remove wire:target="applyAiJob">Apply Changes</span>
                                </x-primary-button>
                                <button type="button" wire:click="discardAiJob"
                                    class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    Discard
                                </button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @if ($formHealth !== [])
            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Form Health</h2>
                        <p class="text-sm text-gray-600 mt-1">{{ $formHealth['summary'] ?? '' }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-bold text-gray-900">
                            {{ $formHealth['score'] ?? 0 }}
                            <span class="text-lg font-normal text-gray-500">/ 100</span>
                        </p>
                        <p @class([
                            'text-sm font-medium mt-1',
                            'text-green-700' => ($formHealth['grade'] ?? '') === 'Excellent',
                            'text-indigo-700' => ($formHealth['grade'] ?? '') === 'Good',
                            'text-yellow-700' => ($formHealth['grade'] ?? '') === 'Needs Improvement',
                            'text-orange-700' => ($formHealth['grade'] ?? '') === 'Poor',
                            'text-red-700' => ($formHealth['grade'] ?? '') === 'Critical',
                        ])>{{ $formHealth['grade'] ?? 'Unknown' }}</p>
                        <button type="button" wire:click="refreshFormHealth"
                            class="mt-2 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                            Refresh
                        </button>
                    </div>
                </div>

                @if (! empty($formHealth['categories']))
                    <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
                        @foreach ($formHealth['categories'] as $category)
                            <div class="rounded-md border border-gray-200 p-3">
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $category['label'] }}</p>
                                <p class="mt-1 text-sm font-semibold text-gray-900">
                                    {{ $category['score'] }} / {{ $category['max'] }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! empty($formHealth['issues']))
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Issues</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach ($formHealth['issues'] as $issue)
                                <li @class([
                                    'text-sm flex items-start gap-2',
                                    'text-red-700' => ($issue['severity'] ?? '') === 'error',
                                    'text-yellow-800' => ($issue['severity'] ?? '') === 'warning',
                                    'text-gray-600' => ($issue['severity'] ?? '') === 'info',
                                ])>
                                    <span aria-hidden="true">
                                        @if (($issue['severity'] ?? '') === 'error')
                                            ✕
                                        @elseif (($issue['severity'] ?? '') === 'warning')
                                            ⚠
                                        @else
                                            •
                                        @endif
                                    </span>
                                    <span>{{ $issue['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! empty($formHealth['suggestions']))
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Recommendations</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach ($formHealth['suggestions'] as $suggestion)
                                <li class="text-sm text-gray-700">• {{ $suggestion }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 px-4 sm:px-6">
                <nav class="flex gap-6">
                    <button type="button" wire:click="setTab('builder')"
                        @class([
                            'py-4 text-sm font-medium border-b-2',
                            'border-indigo-500 text-indigo-600' => $activeTab === 'builder',
                            'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'builder',
                        ])>
                        Builder
                    </button>
                    <button type="button" wire:click="setTab('json')"
                        @class([
                            'py-4 text-sm font-medium border-b-2',
                            'border-indigo-500 text-indigo-600' => $activeTab === 'json',
                            'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'json',
                        ])>
                        JSON
                    </button>
                </nav>
            </div>

            @if ($activeTab === 'builder')
                <div class="grid lg:grid-cols-12 gap-0 min-h-[640px]">
                    <aside class="lg:col-span-3 border-r border-gray-200 p-4 space-y-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Sections</h2>
                            <button type="button" wire:click="addSection" wire:loading.attr="disabled" wire:target="addSection"
                                class="text-sm text-indigo-600 hover:text-indigo-800 font-medium disabled:opacity-50">
                                <span wire:loading wire:target="addSection">Adding...</span>
                                <span wire:loading.remove wire:target="addSection">+ Add</span>
                            </button>
                        </div>

                        <ul class="space-y-2" data-sortable-sections>
                            @forelse ($form->sections as $section)
                                <li data-id="{{ $section->id }}"
                                    @class([
                                        'rounded-md border px-3 py-2 cursor-move',
                                        'border-indigo-500 bg-indigo-50' => $selectedSectionId === $section->id,
                                        'border-gray-200 bg-white' => $selectedSectionId !== $section->id,
                                    ])>
                                    <button type="button" wire:click="selectSection({{ $section->id }})"
                                        class="w-full text-left text-sm font-medium text-gray-900">
                                        {{ $section->title }}
                                    </button>
                                </li>
                            @empty
                                <li class="rounded-md border border-dashed border-gray-300 p-4 text-center">
                                    <p class="text-sm text-gray-600">No sections yet.</p>
                                    <p class="text-xs text-gray-500 mt-1">Sections group related fields on your form.</p>
                                    <button type="button" wire:click="addSection" wire:loading.attr="disabled" wire:target="addSection"
                                        class="mt-3 inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50">
                                        Add first section
                                    </button>
                                </li>
                            @endforelse
                        </ul>
                    </aside>

                    <section class="lg:col-span-5 p-4 space-y-4 overflow-y-auto">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Canvas</h2>

                        @forelse ($form->sections as $section)
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 space-y-2">
                                        <label class="sr-only" for="section-title-{{ $section->id }}">Section title</label>
                                        <input id="section-title-{{ $section->id }}" type="text" value="{{ $section->title }}"
                                            wire:change="updateSectionTitle({{ $section->id }}, $event.target.value)"
                                            class="w-full text-lg font-medium bg-transparent border-0 border-b border-gray-300 focus:border-indigo-500 focus:ring-0 px-0" />
                                        <label class="sr-only" for="section-description-{{ $section->id }}">Section description</label>
                                        <textarea id="section-description-{{ $section->id }}" rows="2"
                                            placeholder="Section description (optional)"
                                            wire:change="updateSectionDescription({{ $section->id }}, $event.target.value)"
                                            class="w-full text-sm text-gray-600 bg-transparent border-gray-200 rounded-md focus:border-indigo-500 focus:ring-indigo-500">{{ $section->description }}</textarea>
                                    </div>
                                    <button type="button"
                                        wire:click="deleteSection({{ $section->id }})"
                                        wire:confirm="Delete this section and all of its fields?"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteSection"
                                        class="text-sm text-red-600 hover:text-red-800 shrink-0">Delete</button>
                                </div>

                                <ul class="space-y-2" data-sortable-fields data-section-id="{{ $section->id }}">
                                    @forelse ($section->fields as $field)
                                        <li data-id="{{ $field->id }}"
                                            wire:key="field-{{ $field->id }}"
                                            @class([
                                                'rounded-md border bg-white px-3 py-3 cursor-move',
                                                'border-indigo-500 ring-1 ring-indigo-500' => $selectedFieldId === $field->id,
                                                'border-gray-200' => $selectedFieldId !== $field->id,
                                            ])>
                                            <div class="flex items-center justify-between gap-3">
                                                <button type="button" wire:click="selectField({{ $field->id }})"
                                                    class="flex-1 text-left">
                                                    <p class="font-medium text-gray-900">{{ $field->label }}</p>
                                                    <p class="text-xs text-gray-500">{{ $field->type }} · {{ $field->key }}
                                                        @if ($field->is_required) · required @endif
                                                    </p>
                                                </button>
                                                <div class="flex items-center gap-2">
                                                    <button type="button" wire:click="duplicateField({{ $field->id }})"
                                                        class="text-xs text-gray-600 hover:text-gray-900">Duplicate</button>
                                                    <button type="button" wire:click="deleteField({{ $field->id }})"
                                                        wire:confirm="Delete this field?"
                                                        class="text-xs text-red-600 hover:text-red-800">Delete</button>
                                                </div>
                                            </div>
                                        </li>
                                    @empty
                                        <li class="rounded-md border border-dashed border-gray-200 bg-white p-4 text-center">
                                            <p class="text-sm text-gray-600">No fields in this section.</p>
                                            <p class="text-xs text-gray-500 mt-1">Add a field to collect responses.</p>
                                        </li>
                                    @endforelse
                                </ul>

                                <button type="button" wire:click="addField({{ $section->id }})"
                                    wire:loading.attr="disabled" wire:target="addField"
                                    class="text-sm text-indigo-600 hover:text-indigo-800 font-medium disabled:opacity-50">
                                    <span wire:loading wire:target="addField">Adding...</span>
                                    <span wire:loading.remove wire:target="addField">+ Add Field</span>
                                </button>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center">
                                <p class="text-sm font-medium text-gray-900">Your form canvas is empty</p>
                                <p class="text-sm text-gray-500 mt-2">Start by adding a section. Each section can hold one or more fields.</p>
                                <button type="button" wire:click="addSection" wire:loading.attr="disabled" wire:target="addSection"
                                    class="mt-4 inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50">
                                    Add first section
                                </button>
                            </div>
                        @endforelse
                    </section>

                    <aside class="lg:col-span-4 border-l border-gray-200 p-4">
                        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Field Configuration</h2>

                        @if ($selectedFieldId)
                            <form wire:submit="saveSelectedField" class="space-y-4">
                                <div>
                                    <x-input-label for="field-label" value="Label" />
                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.label" id="field-label" class="block mt-1 w-full" type="text" />
                                    <x-input-error :messages="$errors->get('fieldEditor.label')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="field-key" value="Key" />
                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.key" id="field-key" class="block mt-1 w-full" type="text" />
                                    <p class="mt-1 text-xs text-gray-500">Lowercase letters, numbers, and underscores. Must start with a letter.</p>
                                    <x-input-error :messages="$errors->get('fieldEditor.key')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="field-type" value="Type" />
                                    <select wire:model.live.debounce.1500ms="fieldEditor.type" id="field-type"
                                        class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        @foreach ($supportedTypes as $type)
                                            <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model.live.debounce.1500ms="fieldEditor.is_required" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                    Required
                                </label>

                                @if (in_array($fieldEditor['type'] ?? '', ['text', 'textarea', 'email', 'number'], true))
                                    <div>
                                        <x-input-label for="field-placeholder" value="Placeholder" />
                                        <x-text-input wire:model.live.debounce.1500ms="fieldEditor.placeholder" id="field-placeholder" class="block mt-1 w-full" type="text" />
                                    </div>
                                @endif

                                @if (in_array($fieldEditor['type'] ?? '', $optionTypes, true))
                                    <div>
                                        <x-input-label for="field-options" value="Options (one per line)" />
                                        <textarea wire:model.live.debounce.1500ms="fieldEditor.optionsText" id="field-options" rows="5"
                                            class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                                    </div>
                                @endif

                                @if (in_array($fieldEditor['type'] ?? '', ['email', 'date', 'number', 'text', 'textarea'], true))
                                    <div class="rounded-md border border-gray-200 p-4 space-y-3">
                                        <p class="text-sm font-medium text-gray-900">Validation</p>

                                        @if (($fieldEditor['type'] ?? '') === 'email')
                                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                                <input type="checkbox" wire:model.live.debounce.1500ms="fieldEditor.validation_format_enabled" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                                Email format
                                            </label>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <x-input-label for="field-email-min-length" value="Minimum length" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_min_length" id="field-email-min-length" class="block mt-1 w-full" type="number" min="0" />
                                                </div>
                                                <div>
                                                    <x-input-label for="field-email-max-length" value="Maximum length" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_max_length" id="field-email-max-length" class="block mt-1 w-full" type="number" min="0" />
                                                </div>
                                            </div>
                                        @endif

                                        @if (($fieldEditor['type'] ?? '') === 'date')
                                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                                <input type="checkbox" wire:model.live.debounce.1500ms="fieldEditor.validation_format_enabled" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                                Date format (Y-m-d)
                                            </label>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <x-input-label for="field-date-min" value="Minimum date" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_min" id="field-date-min" class="block mt-1 w-full" type="date" />
                                                </div>
                                                <div>
                                                    <x-input-label for="field-date-max" value="Maximum date" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_max" id="field-date-max" class="block mt-1 w-full" type="date" />
                                                </div>
                                            </div>
                                        @endif

                                        @if (($fieldEditor['type'] ?? '') === $numberType)
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <x-input-label for="field-min" value="Minimum" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_min" id="field-min" class="block mt-1 w-full" type="number" />
                                                </div>
                                                <div>
                                                    <x-input-label for="field-max" value="Maximum" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_max" id="field-max" class="block mt-1 w-full" type="number" />
                                                </div>
                                            </div>
                                        @endif

                                        @if (in_array($fieldEditor['type'] ?? '', ['text', 'textarea'], true))
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <x-input-label for="field-text-min-length" value="Minimum length" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_min_length" id="field-text-min-length" class="block mt-1 w-full" type="number" min="0" />
                                                </div>
                                                <div>
                                                    <x-input-label for="field-text-max-length" value="Maximum length" />
                                                    <x-text-input wire:model.live.debounce.1500ms="fieldEditor.validation_max_length" id="field-text-max-length" class="block mt-1 w-full" type="number" min="0" />
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveSelectedField">
                                    <span wire:loading wire:target="saveSelectedField">Saving...</span>
                                    <span wire:loading.remove wire:target="saveSelectedField">Save Field</span>
                                </x-primary-button>
                            </form>
                        @else
                            <div class="rounded-md border border-dashed border-gray-200 p-6 text-center">
                                <p class="text-sm font-medium text-gray-900">No field selected</p>
                                <p class="text-sm text-gray-500 mt-2">Click a field in the canvas to edit its label, type, options, and validation.</p>
                            </div>
                        @endif
                    </aside>
                </div>
            @else
                <div class="p-4 sm:p-6 space-y-4">
                    <div class="rounded-md bg-gray-50 border border-gray-200 p-4 text-sm text-gray-700 space-y-2">
                        <p class="font-medium text-gray-900">JSON schema editor</p>
                        <p>Edits here reflect the compiled form structure. Switch to the Builder tab to see changes on the canvas after applying valid JSON.</p>
                        <p>Invalid JSON is rejected and will not overwrite your current form.</p>
                    </div>

                    <textarea wire:model.live.debounce.1500ms="jsonEditor" rows="24" aria-label="Form schema JSON"
                        class="w-full font-mono text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>

                    @if ($jsonError)
                        <div class="rounded-md bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $jsonError }}</div>
                    @endif

                    <x-primary-button type="button" wire:click="applyJson" wire:loading.attr="disabled" wire:target="applyJson">
                        <span wire:loading wire:target="applyJson">Applying...</span>
                        <span wire:loading.remove wire:target="applyJson">Apply JSON</span>
                    </x-primary-button>
                </div>
            @endif
        </div>
    </div>
</div>
