const { TextControl, TextareaControl } = wp.components;

wp.blocks.registerBlockType('eshop/advantage-item', {
    attributes: {
        text: { type: 'string', default: '' },
        icon: { type: 'string', default: '' }
    },

    edit({ attributes, setAttributes }) {
        const blockProps = wp.blockEditor.useBlockProps({
            className: 'advantage-editor'
        });

        return wp.element.createElement(
            'div',
            blockProps,

            wp.element.createElement(TextareaControl, {
                label: 'Текст',
                value: attributes.text,
                rows: 4,
                onChange: (v) => setAttributes({ text: v })
            }),

            wp.element.createElement(TextControl, {
                label: 'Иконка (например: fas fa-shipping-fast)',
                value: attributes.icon,
                onChange: (v) => setAttributes({ icon: v })
            })
        );
    },

    save() {
        return null;
    }
});