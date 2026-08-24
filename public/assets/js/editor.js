"use strict";

$(function () {
    const $editor = $('#editor');
    const $editor1 = $('#editor1');
    const $buttons = $("button");

    const isEditorBloqueado = $('#descripcion_evento_bloqueado').val() === '1';

    const editorOptions = {
        btns: [
            ['viewHTML'],
            ['undo', 'redo'],
            ['formatting'],
            ['strong', 'em', 'del'],
            ['superscript', 'subscript'],
            ['justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'],
            ['unorderedList', 'orderedList'],
            ['horizontalRule'],
            ['removeformat'],
            ['fullscreen']
        ],
        autogrow: true,
        disabled: isEditorBloqueado
    };

    const editor1Options = {
        btns: [
            ['strong', 'em'],
            ['justifyLeft', 'justifyCenter'],
            ['insertImage', 'link'],
        ],
        autogrow: true
    };

    // Inicializar Trumbowyg para #editor
    if ($editor.length) {
        try {
            $editor.trumbowyg(editorOptions);

            if (isEditorBloqueado) {
                $editor.trumbowyg('disable');
            }
        } catch (e) {
            console.error("Error initializing Trumbowyg for #editor:", e);
        }
    }

    // Inicializar Trumbowyg para #editor1
    if ($editor1.length) {
        try {
            $editor1.trumbowyg(editor1Options);
        } catch (e) {
            console.error("Error initializing Trumbowyg for #editor1:", e);
        }
    }

    // Inicializar tooltips
    const initTooltips = () => {
        try {
            $buttons.tooltip();
        } catch (e) {
            console.error("Error initializing tooltips:", e);
        }
    };

    const toggleTooltip = function () {
        try {
            $(this).tooltip('toggle');
        } catch (e) {
            console.error("Error toggling tooltip:", e);
        }
    };

    initTooltips();

    try {
        $buttons.off('click', toggleTooltip).on('click', toggleTooltip);
    } catch (e) {
        console.error("Error binding tooltip toggle events:", e);
    }
});
