const HIDDEN_BLOCKS = [
    'eshop/advantage-item'
];

wp.hooks.addFilter(
    'blocks.registerBlockType',
    'eshop/hide-child-blocks',
    (settings, name) => {
        if (HIDDEN_BLOCKS.includes(name)) {
            settings.category = null;
        }
        return settings;
    }
);