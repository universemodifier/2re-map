(() => {
    'use strict';

    const state = window.APP_STATE;
    const TEXTURES = 'assets/img/';
    const COLORS = { pin: '#20c997', mine: '#0dcaf0', draft: '#ffc107' };

    let pins = [];
    let picking = false;
    let draftLocation = null; // location being edited in the profile form

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    const $ = (sel, root = document) => root.querySelector(sel);

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2)
        .map((part) => part[0]).join('').toUpperCase();

    const debounce = (fn, ms) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    };

    async function api(action, { method = 'GET', params = {}, body = null } = {}) {
        const query = new URLSearchParams({ action, ...params });
        const response = await fetch(`api.php?${query}`, {
            method,
            body,
            credentials: 'same-origin',
            headers: method === 'POST' ? { 'X-CSRF-Token': state.csrf } : {},
        });
        let data = {};
        try {
            data = await response.json();
        } catch {
            // non-JSON response, handled below
        }
        if (!response.ok) {
            throw new Error(data.error || `Request failed (${response.status})`);
        }
        return data;
    }

    function toast(message, variant = 'info') {
        const el = document.createElement('div');
        el.className = `toast align-items-center text-bg-${variant} border-0`;
        el.setAttribute('role', 'status');
        el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(message)}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
        $('#toasts').append(el);
        el.addEventListener('hidden.bs.toast', () => el.remove());
        new bootstrap.Toast(el, { delay: 3500 }).show();
    }

    function showFormError(form, message) {
        const box = $('[data-form-error]', form);
        box.textContent = message || '';
        box.hidden = !message;
    }

    function setBusy(form, busy) {
        form.querySelectorAll('button[type=submit]').forEach((btn) => {
            btn.disabled = busy;
        });
    }

    // -----------------------------------------------------------------------
    // Globe
    // -----------------------------------------------------------------------

    const globeEl = $('#globe');
    const globe = new Globe(globeEl)
        .globeImageUrl(TEXTURES + 'earth-night.jpg')
        .bumpImageUrl(TEXTURES + 'earth-topology.png')
        .backgroundImageUrl(TEXTURES + 'night-sky.png')
        .atmosphereColor('#4da3ff')
        .atmosphereAltitude(0.18)
        .pointsMerge(false)
        .pointLat('lat')
        .pointLng('lng')
        .pointAltitude(0.07)
        .pointRadius(0.5)
        .pointResolution(16)
        .pointColor((d) => (isMine(d) ? COLORS.mine : COLORS.pin))
        .pointLabel((d) => `<div class="globe-tooltip">${esc(d.name)}${d.location ? ` · <span class="text-secondary">${esc(d.location)}</span>` : ''}</div>`)
        .onPointHover((d) => {
            if (!picking) globeEl.style.cursor = d ? 'pointer' : '';
        })
        .onPointClick((d, event, coords) => {
            if (picking) {
                pickLocation(coords.lat, coords.lng);
            } else {
                showCard(d, event);
            }
        })
        .onGlobeClick(({ lat, lng }) => {
            if (picking) {
                pickLocation(lat, lng);
            } else {
                hideCard();
            }
        })
        .ringLat('lat')
        .ringLng('lng')
        .ringColor((d) => (t) => `rgba(${d.rgb}, ${1 - t})`)
        .ringMaxRadius(3)
        .ringPropagationSpeed(2)
        .ringRepeatPeriod(1200)
        .onGlobeReady(() => $('#globe-loading')?.remove());

    const controls = globe.controls();
    controls.autoRotate = true;
    controls.autoRotateSpeed = 0.35;
    controls.addEventListener('start', () => {
        controls.autoRotate = false;
        hideCard();
    });
    globe.pointOfView({ lat: 25, lng: 10, altitude: 2.4 });

    const resizeGlobe = () => globe.width(globeEl.clientWidth).height(globeEl.clientHeight);
    window.addEventListener('resize', resizeGlobe);
    resizeGlobe();

    function isMine(pin) {
        return state.user && pin.id === state.user.id;
    }

    function updateRings() {
        const rings = [];
        const mine = state.user && pins.find(isMine);
        if (mine) rings.push({ lat: mine.lat, lng: mine.lng, rgb: '13, 202, 240' });
        if (draftLocation) rings.push({ lat: draftLocation.lat, lng: draftLocation.lng, rgb: '255, 193, 7' });
        globe.ringsData(rings);
    }

    async function loadPins() {
        try {
            const data = await api('pins');
            pins = data.pins;
            globe.pointsData(pins);
            $('#pin-count').textContent = pins.length;
            updateRings();
        } catch (err) {
            toast(err.message, 'danger');
        }
    }

    function flyTo(lat, lng, altitude = 1.6) {
        controls.autoRotate = false;
        globe.pointOfView({ lat, lng, altitude }, 1200);
    }

    // -----------------------------------------------------------------------
    // Floating info card
    // -----------------------------------------------------------------------

    const card = $('#pin-card');

    function badgeList(items, cls) {
        if (!items.length) return '<span class="text-secondary small">–</span>';
        return items.map((item) => `<span class="badge rounded-pill ${cls}">${esc(item)}</span>`).join(' ');
    }

    function showCard(pin, event) {
        const avatar = pin.picture
            ? `<img class="avatar" src="${esc(pin.picture)}" alt="">`
            : `<div class="avatar">${esc(initials(pin.name))}</div>`;

        card.innerHTML = `
            <button type="button" class="btn-close btn-sm card-close" aria-label="Close"></button>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3 pe-4">
                    ${avatar}
                    <div class="min-w-0">
                        <h2 class="h6 mb-1 text-truncate">${esc(pin.name)}</h2>
                        <div class="small text-secondary"><i class="bi bi-geo-alt me-1"></i>${esc(pin.location)}</div>
                    </div>
                </div>
                <div class="mb-2">
                    <div class="small text-uppercase text-secondary mb-1"><i class="bi bi-life-preserver text-warning me-1"></i>Needs</div>
                    ${badgeList(pin.needs, 'text-bg-warning')}
                </div>
                <div class="mb-2">
                    <div class="small text-uppercase text-secondary mb-1"><i class="bi bi-tools text-success me-1"></i>Skills</div>
                    ${badgeList(pin.skills, 'text-bg-success')}
                </div>
                ${pin.notes ? `<div class="small text-uppercase text-secondary mt-3 mb-1">Notes</div><div class="notes small">${esc(pin.notes)}</div>` : ''}
                ${isMine(pin) ? '<button type="button" class="btn btn-outline-info btn-sm w-100 mt-3" data-action="edit-profile"><i class="bi bi-pencil me-1"></i>Edit my pin</button>' : ''}
            </div>`;
        $('.card-close', card).addEventListener('click', hideCard);

        card.hidden = false;
        positionCard(event);
    }

    function positionCard(event) {
        const margin = 16;
        const rect = card.getBoundingClientRect();
        let x = (event?.clientX ?? window.innerWidth / 2) + margin;
        let y = (event?.clientY ?? window.innerHeight / 2) - rect.height / 2;

        if (x + rect.width > window.innerWidth - margin) {
            x = (event?.clientX ?? window.innerWidth) - rect.width - margin;
        }
        x = Math.max(margin, Math.min(x, window.innerWidth - rect.width - margin));
        y = Math.max(56 + margin, Math.min(y, window.innerHeight - rect.height - margin));

        card.style.left = `${x}px`;
        card.style.top = `${y}px`;
    }

    function hideCard() {
        card.hidden = true;
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (picking) cancelPick();
        else hideCard();
    });

    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------

    function bindAuthForm(formId, action, onSuccess) {
        const form = $(formId);
        if (!form) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            showFormError(form, '');
            setBusy(form, true);
            try {
                await api(action, { method: 'POST', body: new FormData(form) });
                onSuccess();
            } catch (err) {
                showFormError(form, err.message);
                setBusy(form, false);
            }
        });
    }

    bindAuthForm('#login-form', 'login', () => window.location.reload());
    bindAuthForm('#register-form', 'register', () => {
        window.location.href = './?welcome=1';
    });

    // Focus the first field whenever a modal opens.
    document.querySelectorAll('.modal').forEach((modal) => {
        modal.addEventListener('shown.bs.modal', () => {
            if (modal.id !== 'profileModal') modal.querySelector('input')?.focus();
        });
    });

    // -----------------------------------------------------------------------
    // Tag input (needs / skills)
    // -----------------------------------------------------------------------

    function createTagInput(root) {
        const input = $('input', root);
        const badgeClass = root.dataset.tagClass;
        let items = [];

        function render() {
            root.querySelectorAll('.badge').forEach((el) => el.remove());
            items.forEach((item, index) => {
                const badge = document.createElement('span');
                badge.className = `badge rounded-pill d-inline-flex align-items-center ${badgeClass}`;
                badge.textContent = item;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', `Remove ${item}`);
                remove.innerHTML = '<i class="bi bi-x"></i>';
                remove.addEventListener('click', (event) => {
                    event.stopPropagation();
                    items.splice(index, 1);
                    render();
                });
                badge.append(remove);
                root.insertBefore(badge, input);
            });
        }

        function add(raw) {
            raw.split(',').map((s) => s.trim().slice(0, state.limits.itemLength)).forEach((value) => {
                if (!value || items.length >= state.limits.items) return;
                if (items.some((item) => item.toLowerCase() === value.toLowerCase())) return;
                items.push(value);
            });
            input.value = '';
            render();
        }

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                add(input.value);
            } else if (event.key === 'Backspace' && input.value === '' && items.length) {
                items.pop();
                render();
            }
        });
        input.addEventListener('blur', () => add(input.value));
        root.addEventListener('click', () => input.focus());

        return {
            get: () => {
                add(input.value); // include text typed but not yet confirmed
                return [...items];
            },
            set: (values) => {
                items = [...(values || [])];
                input.value = '';
                render();
            },
        };
    }

    // -----------------------------------------------------------------------
    // Profile editor
    // -----------------------------------------------------------------------

    const profileForm = $('#profile-form');
    const profileModalEl = $('#profileModal');
    const profileModal = profileModalEl ? new bootstrap.Modal(profileModalEl) : null;
    const tags = {};
    let pictureFile = null;
    let removePicture = false;

    if (profileForm) {
        document.querySelectorAll('[data-tag-input]').forEach((root) => {
            tags[root.dataset.tagInput] = createTagInput(root);
        });

        const notes = $('#pf-notes');
        const updateNotesCount = () => {
            $('#pf-notes-count').textContent = `${notes.value.length} / ${state.limits.notes}`;
        };
        notes.addEventListener('input', updateNotesCount);

        $('#pf-picture').addEventListener('change', (event) => {
            const file = event.target.files[0];
            if (!file) return;
            pictureFile = file;
            removePicture = false;
            renderAvatarPreview(URL.createObjectURL(file));
        });

        $('#pf-remove-picture').addEventListener('click', () => {
            pictureFile = null;
            removePicture = true;
            $('#pf-picture').value = '';
            renderAvatarPreview(null);
        });

        profileForm.addEventListener('submit', saveProfile);
        profileModalEl.addEventListener('hidden.bs.modal', () => {
            if (!picking) {
                draftLocation = null;
                updateRings();
            }
        });

        setupLocationSearch();

        // Expose for openProfile()
        profileForm.updateNotesCount = updateNotesCount;
    }

    function renderAvatarPreview(url) {
        const preview = $('#avatar-preview');
        preview.classList.toggle('has-image', Boolean(url));
        preview.style.backgroundImage = url ? `url("${url}")` : '';
        preview.innerHTML = url ? '' : '<i class="bi bi-person"></i>';
        $('#pf-remove-picture').hidden = !url;
    }

    function renderDraftLocation() {
        const target = $('#pf-location-current');
        if (!draftLocation) {
            target.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-circle me-1"></i>No location yet – search a city or pick a spot on the globe.</span>';
            return;
        }
        target.innerHTML = `<span class="text-info"><i class="bi bi-geo-alt-fill me-1"></i>${esc(draftLocation.label)}</span>
            <span class="text-secondary ms-1">(${draftLocation.lat.toFixed(3)}, ${draftLocation.lng.toFixed(3)})</span>`;
        updateRings();
    }

    function openProfile() {
        if (!profileModal) return;
        hideCard();
        const user = state.user;
        showFormError(profileForm, '');
        $('#pf-name').value = user.name;
        $('#pf-notes').value = user.notes || '';
        profileForm.updateNotesCount();
        tags.needs.set(user.needs);
        tags.skills.set(user.skills);
        pictureFile = null;
        removePicture = false;
        $('#pf-picture').value = '';
        renderAvatarPreview(user.picture);
        $('#pf-location-search').value = '';
        draftLocation = user.lat !== null
            ? { lat: user.lat, lng: user.lng, label: user.location }
            : null;
        renderDraftLocation();
        profileModal.show();
    }

    async function saveProfile(event) {
        event.preventDefault();
        showFormError(profileForm, '');

        const name = $('#pf-name').value.trim();
        if (!name) {
            showFormError(profileForm, 'Please enter your name.');
            return;
        }
        if (!draftLocation) {
            showFormError(profileForm, 'Please choose a location – search for a city or pick a spot on the globe.');
            return;
        }

        const body = new FormData();
        body.append('name', name);
        body.append('notes', $('#pf-notes').value);
        body.append('needs', JSON.stringify(tags.needs.get()));
        body.append('skills', JSON.stringify(tags.skills.get()));
        body.append('lat', draftLocation.lat);
        body.append('lng', draftLocation.lng);
        body.append('location_label', draftLocation.label);
        if (removePicture) body.append('remove_picture', '1');

        setBusy(profileForm, true);
        try {
            if (pictureFile) {
                const scaled = await downscaleImage(pictureFile, 800);
                body.append('picture', scaled, scaled.name || pictureFile.name);
            }
            const data = await api('profile', { method: 'POST', body });
            state.user = data.user;
            const navName = $('#nav-user-name');
            if (navName) navName.textContent = data.user.name;
            const pinLabel = $('#nav-pin-label');
            if (pinLabel) pinLabel.textContent = 'Edit my pin';
            draftLocation = null;
            profileModal.hide();
            await loadPins();
            flyTo(data.user.lat, data.user.lng);
            toast('Your pin has been saved.', 'success');
        } catch (err) {
            showFormError(profileForm, err.message);
        } finally {
            setBusy(profileForm, false);
        }
    }

    /** Shrink large photos in the browser so uploads stay small and fast. */
    function downscaleImage(file, maxPx) {
        if (file.type === 'image/gif') return Promise.resolve(file);
        return new Promise((resolve) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                URL.revokeObjectURL(url);
                const scale = Math.min(1, maxPx / Math.max(img.width, img.height));
                if (scale === 1 && file.size < 1024 * 1024) {
                    resolve(file);
                    return;
                }
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                canvas.toBlob((blob) => {
                    resolve(blob ? new File([blob], 'picture.jpg', { type: 'image/jpeg' }) : file);
                }, 'image/jpeg', 0.88);
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                resolve(file);
            };
            img.src = url;
        });
    }

    // -----------------------------------------------------------------------
    // Location: city search + pick on globe
    // -----------------------------------------------------------------------

    function setupLocationSearch() {
        const input = $('#pf-location-search');
        const results = $('#location-results');
        let lastQuery = '';

        const hideResults = () => {
            results.hidden = true;
            results.innerHTML = '';
        };

        const search = debounce(async (query) => {
            if (query.length < 2) {
                hideResults();
                return;
            }
            lastQuery = query;
            results.hidden = false;
            results.innerHTML = '<div class="list-group-item small text-secondary"><span class="spinner-border spinner-border-sm me-2"></span>Searching…</div>';
            try {
                const data = await api('geocode', { params: { q: query } });
                if (query !== lastQuery) return; // a newer search is running
                if (!data.results.length) {
                    results.innerHTML = '<div class="list-group-item small text-secondary">No places found.</div>';
                    return;
                }
                results.innerHTML = '';
                data.results.forEach((place) => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'list-group-item list-group-item-action';
                    item.innerHTML = `<div class="fw-semibold">${esc(place.label)}</div>
                        <div class="small text-secondary text-truncate">${esc(place.detail)}</div>`;
                    item.addEventListener('click', () => {
                        draftLocation = { lat: place.lat, lng: place.lng, label: place.label };
                        renderDraftLocation();
                        flyTo(place.lat, place.lng, 1.8);
                        input.value = '';
                        hideResults();
                    });
                    results.append(item);
                });
            } catch (err) {
                results.innerHTML = `<div class="list-group-item small text-danger">${esc(err.message)}</div>`;
            }
        }, 450);

        input.addEventListener('input', () => search(input.value.trim()));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                results.querySelector('button')?.click();
            }
        });
        document.addEventListener('click', (event) => {
            if (!results.contains(event.target) && event.target !== input) hideResults();
        });
    }

    function startPick() {
        picking = true;
        hideCard();
        profileModal.hide();
        globeEl.classList.add('picking');
        globeEl.style.cursor = '';
        $('#pick-banner').hidden = false;
    }

    function endPick() {
        picking = false;
        globeEl.classList.remove('picking');
        $('#pick-banner').hidden = true;
        profileModal.show();
    }

    function cancelPick() {
        endPick();
    }

    async function pickLocation(lat, lng) {
        draftLocation = { lat, lng, label: `${lat.toFixed(2)}, ${lng.toFixed(2)}` };
        updateRings();
        endPick();
        renderDraftLocation();
        try {
            const data = await api('reverse', { params: { lat, lng } });
            // Ignore the answer if the user picked something else meanwhile.
            if (draftLocation && draftLocation.lat === lat && draftLocation.lng === lng) {
                draftLocation.label = data.label;
                renderDraftLocation();
            }
        } catch {
            // keep coordinate label
        }
    }

    // -----------------------------------------------------------------------
    // Global actions
    // -----------------------------------------------------------------------

    const actions = {
        'edit-profile': openProfile,
        'start-pick': startPick,
        'cancel-pick': cancelPick,
        'fly-home': () => {
            const mine = pins.find(isMine);
            if (mine) flyTo(mine.lat, mine.lng);
            else openProfile();
        },
        logout: async () => {
            try {
                await api('logout', { method: 'POST' });
            } finally {
                window.location.href = './';
            }
        },
        'delete-account': async () => {
            if (!window.confirm('Delete your account and remove your pin from the map? This cannot be undone.')) return;
            try {
                await api('delete_account', { method: 'POST' });
                window.location.href = './';
            } catch (err) {
                toast(err.message, 'danger');
            }
        },
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-action]');
        if (trigger && actions[trigger.dataset.action]) {
            event.preventDefault();
            actions[trigger.dataset.action]();
        }
    });

    // -----------------------------------------------------------------------
    // Start
    // -----------------------------------------------------------------------

    loadPins();

    const params = new URLSearchParams(window.location.search);
    if (params.has('welcome') && state.user) {
        window.history.replaceState(null, '', './');
        toast(`Welcome, ${state.user.name}! Add your needs, skills and location to place your pin.`, 'success');
        openProfile();
    }
})();
