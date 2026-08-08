import Sortable from 'sortablejs';

window.FormBuilderSortable = {
    instances: [],

    destroy() {
        this.instances.forEach((instance) => instance.destroy());
        this.instances = [];
    },

    init(component) {
        this.destroy();

        document.querySelectorAll('[data-sortable-sections]').forEach((element) => {
            this.instances.push(Sortable.create(element, {
                animation: 150,
                handle: 'li',
                onEnd: () => {
                    const ids = [...element.querySelectorAll('[data-id]')]
                        .map((child) => parseInt(child.dataset.id, 10))
                        .filter(Boolean);

                    if (ids.length) {
                        component.reorderSections(ids);
                    }
                },
            }));
        });

        document.querySelectorAll('[data-sortable-fields]').forEach((element) => {
            const sectionId = parseInt(element.dataset.sectionId, 10);

            this.instances.push(Sortable.create(element, {
                animation: 150,
                handle: 'li',
                onEnd: () => {
                    const ids = [...element.querySelectorAll('[data-id]')]
                        .map((child) => parseInt(child.dataset.id, 10))
                        .filter(Boolean);

                    if (ids.length && sectionId) {
                        component.reorderFields(sectionId, ids);
                    }
                },
            }));
        });
    },
};

document.addEventListener('livewire:init', () => {
    const boot = () => {
        const root = document.querySelector('[data-form-builder-root]');

        if (! root) {
            return;
        }

        const wireId = root.getAttribute('wire:id');

        if (! wireId || ! window.Livewire) {
            return;
        }

        const component = window.Livewire.find(wireId);

        if (component) {
            window.FormBuilderSortable.init(component);
        }
    };

    boot();

    Livewire.hook('morph.updated', ({ el }) => {
        if (el.matches('[data-form-builder-root]') || el.closest('[data-form-builder-root]')) {
            boot();
        }
    });
});
