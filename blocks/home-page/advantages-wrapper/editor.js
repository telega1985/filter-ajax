const { InnerBlocks } = wp.blockEditor;

wp.blocks.registerBlockType('eshop/advantages-wrapper', {
    edit() {
        const blockProps = wp.blockEditor.useBlockProps();

        return wp.element.createElement(
            'div',
            blockProps,

            wp.element.createElement(
                'div',
                { className: 'advantages-wrapper-title' },
                'Секция: Преимущества'
            ),

            wp.element.createElement(
                'div',
                { className: 'advantages-wrapper-inner' },
                wp.element.createElement(InnerBlocks, {
                    allowedBlocks: ['eshop/advantage-item'],
                    templateLock: false,
                    renderAppender: InnerBlocks.ButtonBlockAppender
                })
            )
        );
    },

    save() {
        return wp.element.createElement(
            InnerBlocks.Content
        );
    }
});