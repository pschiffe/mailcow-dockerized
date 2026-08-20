rcmail.addEventListener('init', function () {
    const button = document.querySelector('#taskmenu > a.button-mailcow-preferences');
    const settings = document.querySelector('#taskmenu > a.settings');

    if (button && settings) {
        settings.insertAdjacentElement('afterend', button);
    }
});
