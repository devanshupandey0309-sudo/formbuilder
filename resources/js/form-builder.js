import Sortable from 'sortablejs';

const recoveryKey = (formId) => `form-builder-recovery:${formId}`;

window.FormBuilderDraftRecovery = {
    read(formId) {
        try {
            const raw = localStorage.getItem(recoveryKey(formId));

            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    },

    write(formId, snapshot) {
        if (! formId || ! snapshot) {
            return;
        }

        localStorage.setItem(recoveryKey(formId), JSON.stringify(snapshot));
    },

    clear(formId) {
        localStorage.removeItem(recoveryKey(formId));
    },

    isNewerThanServer(snapshot, serverSavedAt) {
        const localTimestamp = Date.parse(snapshot?.timestamp ?? '');

        if (Number.isNaN(localTimestamp)) {
            return false;
        }

        const serverTimestamp = serverSavedAt ? Date.parse(serverSavedAt) : 0;

        return localTimestamp > serverTimestamp;
    },
};

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
    const findComponent = () => {
        const root = document.querySelector('[data-form-builder-root]');

        if (! root) {
            return null;
        }

        const wireId = root.getAttribute('wire:id');

        if (! wireId || ! window.Livewire) {
            return null;
        }

        return window.Livewire.find(wireId);
    };

    const bootSortable = () => {
        const component = findComponent();

        if (component) {
            window.FormBuilderSortable.init(component);
        }
    };

    const checkRecovery = (payload) => {
        const component = findComponent();

        if (! component || ! payload?.formId) {
            return;
        }

        const snapshot = window.FormBuilderDraftRecovery.read(payload.formId);

        if (! snapshot) {
            return;
        }

        if (window.FormBuilderDraftRecovery.isNewerThanServer(snapshot, payload.draftSavedAt)) {
            component.call('offerRecovery', snapshot);
        } else {
            window.FormBuilderDraftRecovery.clear(payload.formId);
        }
    };

    bootSortable();

    Livewire.on('draft-recovery-check', (event) => {
        const payload = event?.detail ?? event?.[0] ?? event;
        checkRecovery(payload);
    });

    Livewire.on('draft-changed', (event) => {
        const snapshot = event?.detail?.snapshot ?? event?.snapshot ?? event?.[0]?.snapshot;

        if (snapshot?.formId) {
            window.FormBuilderDraftRecovery.write(snapshot.formId, snapshot);
        }
    });

    Livewire.on('draft-saved', (event) => {
        const payload = event?.detail ?? event?.[0] ?? event;
        const snapshot = payload?.snapshot;
        const formId = payload?.formId ?? snapshot?.formId;

        if (! formId) {
            return;
        }

        if (snapshot) {
            window.FormBuilderDraftRecovery.write(formId, {
                ...snapshot,
                serverRevision: payload.draftRevision,
                serverSavedAt: payload.draftSavedAt,
                timestamp: payload.draftSavedAt ?? snapshot.timestamp,
            });
        }
    });

    Livewire.on('draft-recovery-discard', (event) => {
        const formId = event?.detail?.formId ?? event?.formId ?? event?.[0]?.formId;

        if (formId) {
            window.FormBuilderDraftRecovery.clear(formId);
        }
    });

    Livewire.hook('morph.updated', ({ el }) => {
        if (el.matches('[data-form-builder-root]') || el.closest('[data-form-builder-root]')) {
            bootSortable();
        }
    });
});
