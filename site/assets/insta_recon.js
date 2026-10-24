/* فتح تفاصيل مطابقة إنستاباي بالماوس أو الكيبورد. */
document.querySelectorAll('[data-insta-summary]').forEach(function (row) {
    row.style.cursor = 'pointer';
    function toggle() {
        var detail = row.nextElementSibling;
        if (!detail) return;
        detail.hidden = !detail.hidden;
        row.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true');
    }
    row.addEventListener('click', toggle);
    row.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggle();
        }
    });
});
