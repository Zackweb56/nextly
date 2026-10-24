const renderers = new Map();

export function registerDataTableRenderer(name, renderer) {
    renderers.set(name, renderer);
}

export function getDataTableRenderer(name) {
    return renderers.get(name);
}
