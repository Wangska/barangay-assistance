document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('profile-form');
    const stage = document.getElementById('photo-stage');
    const cropImage = document.getElementById('crop-image');
    const fileInput = document.getElementById('profile_photo');
    const zoom = document.getElementById('crop-zoom');
    const choose = document.getElementById('choose-photo');
    const preview = document.getElementById('photo-preview');
    const modalElement = document.getElementById('photo-modal');
    const modal = new bootstrap.Modal(modalElement);
    let baseScale = 1;
    let zoomFactor = 1;
    let offsetX = 0;
    let offsetY = 0;
    let naturalWidth = 0;
    let naturalHeight = 0;
    let dragging = false;
    let startX = 0;
    let startY = 0;
    let startOffsetX = 0;
    let startOffsetY = 0;
    let ready = false;
    let keepSelection = false;

    const clamp = () => {
        const size = stage.clientWidth;
        const scale = baseScale * zoomFactor;
        offsetX = Math.min(0, Math.max(size - naturalWidth * scale, offsetX));
        offsetY = Math.min(0, Math.max(size - naturalHeight * scale, offsetY));
    };

    const apply = () => {
        const scale = baseScale * zoomFactor;
        cropImage.style.width = (naturalWidth * scale) + 'px';
        cropImage.style.height = (naturalHeight * scale) + 'px';
        cropImage.style.transform = 'translate(' + offsetX + 'px, ' + offsetY + 'px)';
    };

    const layout = () => {
        if (!naturalWidth) return;
        const size = stage.clientWidth;
        baseScale = Math.max(size / naturalWidth, size / naturalHeight);
        zoomFactor = 1.35;
        zoom.value = '1.35';
        offsetX = (size - naturalWidth * baseScale * zoomFactor) / 2;
        offsetY = (size - naturalHeight * baseScale * zoomFactor) / 2;
        clamp();
        ready = true;
        apply();
    };

    choose.addEventListener('click', () => {
        fileInput.value = '';
        fileInput.click();
    });

    fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            fileInput.value = '';
            window.alert('Choose a JPG, PNG, or WebP photo.');
            return;
        }
        const url = URL.createObjectURL(file);
        cropImage.onload = () => {
            naturalWidth = cropImage.naturalWidth;
            naturalHeight = cropImage.naturalHeight;
            ready = false;
            modal.show();
        };
        cropImage.src = url;
    });

    modalElement.addEventListener('shown.bs.modal', layout);
    modalElement.addEventListener('hidden.bs.modal', () => {
        if (!keepSelection) fileInput.value = '';
        keepSelection = false;
        dragging = false;
    });

    stage.addEventListener('pointerdown', (event) => {
        if (!ready) return;
        event.preventDefault();
        dragging = true;
        startX = event.clientX;
        startY = event.clientY;
        startOffsetX = offsetX;
        startOffsetY = offsetY;
        stage.setPointerCapture(event.pointerId);
    });
    stage.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        offsetX = startOffsetX + (event.clientX - startX);
        offsetY = startOffsetY + (event.clientY - startY);
        clamp();
        apply();
    });
    stage.addEventListener('pointerup', () => { dragging = false; });
    stage.addEventListener('pointercancel', () => { dragging = false; });

    zoom.addEventListener('input', () => {
        if (!ready) return;
        const size = stage.clientWidth;
        const previous = baseScale * zoomFactor;
        const nextFactor = Number(zoom.value);
        const next = baseScale * nextFactor;
        offsetX = (size / 2) - (((size / 2) - offsetX) * next / previous);
        offsetY = (size / 2) - (((size / 2) - offsetY) * next / previous);
        zoomFactor = nextFactor;
        clamp();
        apply();
    });

    document.getElementById('save-crop').addEventListener('click', () => {
        if (!ready) return;
        const size = 512;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ratio = size / stage.clientWidth;
        const scale = baseScale * zoomFactor * ratio;
        canvas.getContext('2d').drawImage(cropImage, offsetX * ratio, offsetY * ratio, naturalWidth * scale, naturalHeight * scale);
        canvas.toBlob((blob) => {
            if (!blob) {
                window.alert('The photo could not be prepared. Try another image.');
                return;
            }
            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], 'profile.jpg', { type: 'image/jpeg' }));
            fileInput.files = transfer.files;
            const current = preview.querySelector('.profile-current');
            const image = current || document.createElement('img');
            image.className = 'profile-current';
            image.alt = '';
            image.src = URL.createObjectURL(blob);
            if (!current) {
                preview.querySelector('#photo-placeholder')?.remove();
                preview.insertBefore(image, preview.firstChild);
            }
            keepSelection = true;
            modal.hide();
            form.requestSubmit();
        }, 'image/jpeg', 0.92);
    });
});
