// odc.js - WMS v2 - Ordenes de Compra
window.ODC = {
    init() {
        console.log('ODC inicializado');
    },
    buscarProducto(q) {
        return window.api.get('/odc/buscar-producto?q=' + encodeURIComponent(q));
    }
};
