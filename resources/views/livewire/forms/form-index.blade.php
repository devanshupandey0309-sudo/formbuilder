<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Forms</h1>
                <p class="text-sm text-gray-600 mt-1">Create and manage your dynamic forms.</p>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Create a new form</h2>

                <form wire:submit="createForm" class="space-y-4">
                    <div>
                        <x-input-label for="title" value="Title" />
                        <x-text-input wire:model="title" id="title" class="block mt-1 w-full" type="text" required autofocus />
                        <x-input-error :messages="$errors->get('title')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea wire:model="description" id="description" rows="3" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <x-primary-button>Create Form</x-primary-button>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Your forms</h2>

                @if ($forms->isEmpty())
                    <p class="text-sm text-gray-500">No forms yet. Create your first form to open the builder.</p>
                @else
                    <ul class="divide-y divide-gray-200">
                        @foreach ($forms as $form)
                            <li class="py-4 flex items-center justify-between gap-4">
                                <div>
                                    <p class="font-medium text-gray-900">{{ $form->title }}</p>
                                    <p class="text-sm text-gray-500">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-green-100 text-green-800' => $form->status === 'published',
                                            'bg-yellow-100 text-yellow-800' => $form->status === 'draft',
                                        ])>{{ ucfirst($form->status) }}</span>
                                        @if ($form->status === 'published' && $form->schema === null)
                                            <span class="ml-2 text-amber-600">Needs republish</span>
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ route('forms.builder', $form) }}" wire:navigate class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                                    Open Builder
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
