document.addEventListener('click', function (event) {
    const button = event.target.closest('.ace-redirect-three-months');
    if (!button) return;
    const field = document.getElementById(button.dataset.target);
    if (field) { field.value = button.dataset.date; field.dispatchEvent(new Event('change', {bubbles: true})); }
});
