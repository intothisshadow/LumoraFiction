// Optional manual crop for a story cover. When a picture is chosen, it is drawn on a canvas with a fixed-shape frame
// over it. The frame moves by dragging or with the sliders, and the chosen area is sent as four fractions of the
// picture (crop_x, crop_y, crop_w, crop_h) for the server to apply. Without this script, or without the box ticked,
// the whole picture is sent. The picture is read with createImageBitmap, so nothing is loaded from an address and the
// strict content policy needs no exception.
(function () {
    'use strict';

    var box = document.querySelector('[data-lf-cropper]');
    var input = document.getElementById('cover');

    if (!box || !input || !window.createImageBitmap) {
        return;
    }

    var canvas = box.querySelector('canvas');
    var context = canvas.getContext('2d');
    var use = box.querySelector('[data-lf-crop-use]');
    var zoom = box.querySelector('[data-lf-crop-zoom]');
    var left = box.querySelector('[data-lf-crop-x]');
    var top = box.querySelector('[data-lf-crop-y]');
    var fields = {
        x: box.querySelector('[name="crop_x"]'),
        y: box.querySelector('[name="crop_y"]'),
        w: box.querySelector('[name="crop_w"]'),
        h: box.querySelector('[name="crop_h"]')
    };
    var ratio = parseFloat(box.getAttribute('data-lf-ratio')) || 1.5;
    var bitmap = null;
    var width = 0;
    var height = 0;
    var scale = 1;
    var dragging = false;
    // zoom is 1 or more; across and down are 0 to 1 positions inside the room the frame has to move.
    var state = { zoom: 1, across: 0.5, down: 0.5 };

    function clamp(value) {
        return Math.min(1, Math.max(0, value));
    }

    function frame() {
        var baseWidth = width / height >= ratio ? height * ratio : width;
        var frameWidth = baseWidth / state.zoom;
        var frameHeight = frameWidth / ratio;

        return {
            x: (width - frameWidth) * state.across,
            y: (height - frameHeight) * state.down,
            w: frameWidth,
            h: frameHeight
        };
    }

    function draw() {
        var area = frame();
        var x = area.x * scale;
        var y = area.y * scale;
        var w = area.w * scale;
        var h = area.h * scale;

        context.clearRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        context.fillStyle = 'rgba(0, 0, 0, 0.5)';
        context.fillRect(0, 0, canvas.width, y);
        context.fillRect(0, y + h, canvas.width, canvas.height - y - h);
        context.fillRect(0, y, x, h);
        context.fillRect(x + w, y, canvas.width - x - w, h);
        context.lineWidth = 2;
        context.strokeStyle = '#ffffff';
        context.strokeRect(x, y, w, h);
        context.lineWidth = 1;
        context.strokeStyle = '#222222';
        context.strokeRect(x + 2, y + 2, w - 4, h - 4);
    }

    function update() {
        var area = frame();

        zoom.value = String(Math.round(state.zoom * 100));
        left.value = String(Math.round(state.across * 100));
        top.value = String(Math.round(state.down * 100));
        left.disabled = width - area.w < 1;
        top.disabled = height - area.h < 1;

        if (use.checked) {
            fields.x.value = (area.x / width).toFixed(6);
            fields.y.value = (area.y / height).toFixed(6);
            fields.w.value = (area.w / width).toFixed(6);
            fields.h.value = (area.h / height).toFixed(6);
        } else {
            clearFields();
        }

        draw();
    }

    function clearFields() {
        Object.keys(fields).forEach(function (key) {
            fields[key].value = '';
        });
    }

    function hide() {
        box.hidden = true;
        use.checked = false;
        clearFields();

        if (bitmap) {
            bitmap.close();
            bitmap = null;
        }
    }

    function show(image) {
        if (bitmap) {
            bitmap.close();
        }

        bitmap = image;
        width = image.width;
        height = image.height;
        scale = Math.min(480 / width, 360 / height, 1);
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        state = { zoom: 1, across: 0.5, down: 0.5 };
        use.checked = false;
        box.hidden = false;
        update();
    }

    function load(file) {
        // Browsers that know the option turn a phone photo upright the way the server will; others ignore it.
        return window.createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
            return window.createImageBitmap(file);
        });
    }

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];

        if (!file) {
            hide();

            return;
        }

        load(file).then(show).catch(hide);
    });

    zoom.addEventListener('input', function () {
        state.zoom = Math.max(1, Math.min(4, parseInt(zoom.value, 10) / 100));
        use.checked = true;
        update();
    });

    left.addEventListener('input', function () {
        state.across = clamp(parseInt(left.value, 10) / 100);
        use.checked = true;
        update();
    });

    top.addEventListener('input', function () {
        state.down = clamp(parseInt(top.value, 10) / 100);
        use.checked = true;
        update();
    });

    use.addEventListener('change', update);

    function moveTo(event) {
        var rect = canvas.getBoundingClientRect();
        var area = frame();
        var pointerX = (event.clientX - rect.left) / rect.width * width;
        var pointerY = (event.clientY - rect.top) / rect.height * height;

        state.across = width - area.w < 1 ? 0.5 : clamp((pointerX - area.w / 2) / (width - area.w));
        state.down = height - area.h < 1 ? 0.5 : clamp((pointerY - area.h / 2) / (height - area.h));
        use.checked = true;
        update();
    }

    canvas.addEventListener('pointerdown', function (event) {
        dragging = true;
        canvas.setPointerCapture(event.pointerId);
        moveTo(event);
    });

    canvas.addEventListener('pointermove', function (event) {
        if (dragging) {
            moveTo(event);
        }
    });

    ['pointerup', 'pointercancel'].forEach(function (name) {
        canvas.addEventListener(name, function () {
            dragging = false;
        });
    });
}());
