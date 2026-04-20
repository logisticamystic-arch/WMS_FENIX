        // Tendencia mensual de las top 5 categorías
        $qTopCategorias = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->leftJoin('categoria_productos as cp', 'p.categoria_id', '=', 'cp.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereYear('op.created_at', $anio);
            
        $topCategorias = (clone $qTopCategorias)
            ->select('cp.id', 'cp.nombre', Capsule::raw('SUM(pd.cantidad_pickeada) as total'))
            ->groupBy('cp.id', 'cp.nombre')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->get();
            
        $tendenciaMensualCat = [];
        foreach ($topCategorias as $cat) {
            $tendenciaCatQuery = (clone $qTopCategorias)
                ->where('cp.id', $cat->id)
                ->select(Capsule::raw('MONTH(op.created_at) as mes'), Capsule::raw('SUM(pd.cantidad_pickeada) as total'))
                ->groupBy(Capsule::raw('MONTH(op.created_at)'))
                ->get()
                ->keyBy('mes');
            
            $catData = [];
            for ($i = 1; $i <= 12; $i++) {
                $catData[] = (float)($tendenciaCatQuery->get($i)->total ?? 0);
            }
            
            $tendenciaMensualCat[] = [
                'categoria' => $cat->nombre ?? 'Sin Categoria',
                'data' => $catData
            ];
        }
