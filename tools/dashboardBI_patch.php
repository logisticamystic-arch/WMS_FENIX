<?php
    // ── DASHBOARD BI (ANALÍTICA EXPERTA GERENCIAL) ───────────────────────────
    public function dashboardBI(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $eId    = $user->empresa_id;
        $params = $r->getQueryParams();

        // Filtros globales recibidos desde el Frontend
        $mes       = $params['mes'] ?? date('m');
        $anio      = $params['anio'] ?? date('Y');
        $categoria = $params['categoria'] ?? '';
        $producto  = $params['producto'] ?? '';

        // 1. OBTENER ESTADÍSTICAS COMERCIALES (KARDEX/PICKING COMPLETADO)
        // Ventas Mes a Mes (Ventas = Picking Completado)
        $qVentas = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereYear('op.created_at', $anio);
        
        if ($categoria) {
            $qVentas->where('p.categoria_id', $categoria);
        }
        if ($producto) {
            $qVentas->where('p.id', $producto);
        }

        $ventasMesAMesCols = Capsule::raw('MONTH(op.created_at) as mes, SUM(pd.cantidad_pickeada) as total_ventas');
        $ventasMesAMes = (clone $qVentas)->select($ventasMesAMesCols)
            ->groupBy(Capsule::raw('MONTH(op.created_at)'))->get()->keyBy('mes');
        
        $ventasArray = [];
        for ($i=1; $i<=12; $i++) {
            $ventasArray[] = $ventasMesAMes->get($i)->total_ventas ?? 0;
        }

        // Total Picking por de la Categoría seleccionada (o de todas si no se envía)
        $qCategorias = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->leftJoin('categoria_productos as cp', 'p.categoria_id', '=', 'cp.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereMonth('op.created_at', $mes)
            ->whereYear('op.created_at', $anio);

        if ($categoria) {
            $qCategorias->where('p.categoria_id', $categoria);
        }
        
        $picksPorCategoria = (clone $qCategorias)
            ->select('cp.nombre as categoria', Capsule::raw('SUM(pd.cantidad_pickeada) as total'))
            ->groupBy('cp.id', 'cp.nombre')
            ->orderBy('total', 'desc')
            ->get();

        // Calcular crecimiento (Mes actual vs Mes Anterior)
        $mesAnterior = $mes - 1;
        $anioAnterior = $anio;
        if ($mesAnterior == 0) { $mesAnterior = 12; $anioAnterior--; }

        $pickMesAnterior = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
            ->join('productos as p', 'pd.producto_id', '=', 'p.id')
            ->where('op.empresa_id', $eId)
            ->where('op.estado', 'Completada')
            ->whereMonth('op.created_at', $mesAnterior)
            ->whereYear('op.created_at', $anioAnterior);
        if ($categoria) $pickMesAnterior->where('p.categoria_id', $categoria);

        $totalAnterior = $pickMesAnterior->sum('pd.cantidad_pickeada');
        $totalActual   = $ventasMesAMes->get((int)$mes)->total_ventas ?? 0;
        $crecimiento = 0;
        if ($totalAnterior > 0) {
            $crecimiento = (($totalActual - $totalAnterior) / $totalAnterior) * 100;
        } else if ($totalActual > 0) {
            $crecimiento = 100;
        }

        // Baja Rotación
        // Productos con stock > 0 que no han tenido salidas en los últimos 90 días
        $bajaRotacion = Capsule::table('inventarios as i')
            ->join('productos as p', 'i.producto_id', '=', 'p.id')
            ->leftJoin('categoria_productos as cp', 'p.categoria_id', '=', 'cp.id')
            ->where('i.empresa_id', $eId)
            ->where('i.cantidad', '>', 0)
            ->whereNotExists(function($query) {
                $query->select(Capsule::raw(1))
                      ->from('picking_detalles as pd2')
                      ->join('orden_pickings as op2', 'pd2.orden_picking_id', '=', 'op2.id')
                      ->whereColumn('pd2.producto_id', 'i.producto_id')
                      ->where('op2.estado', 'Completada')
                      ->where(Capsule::raw('DATEDIFF(CURDATE(), op2.created_at)'), '<=', 90);
            })
            ->select('p.codigo_interno', 'p.nombre as producto', 'cp.nombre as categoria', Capsule::raw('SUM(i.cantidad) as stock_inmovilizado'))
            ->groupBy('p.id', 'p.codigo_interno', 'p.nombre', 'cp.nombre')
            ->orderBy('stock_inmovilizado', 'desc')
            ->limit(10)
            ->get();

        // 2. FORECASTING (PROYECCIÓN DE MACHINE LEARNING MOCK - REGRESIÓN LINEAL BÁSICA LOCAL)
        // Se calcula basándose en la tendencia de los meses previos, simulando una interfaz a Python
        $forecastData = [];
        $meses = [];
        for ($i=1; $i<=12; $i++) {
            $real = $ventasMesAMes->get($i)->total_ventas ?? 0;
            if ($i <= $mes) {
                $forecastData[] = null; // null si ya paso
                $meses[] = $real; 
            } else {
                // simple mean of last 3 months + a factor
                $last3 = array_slice($ventasArray, max(0, $mes-3), 3);
                $avg = count($last3) > 0 ? array_sum($last3)/count($last3) : 0;
                $forecast = round($avg * mt_rand(90, 115) / 100);
                $forecastData[] = $forecast;
            }
        }

        // Combinado para gráfico
        $mlData = [
            'reales' => $ventasArray, // Todo hasta diciembre (donde desde mes+1 será 0)
            'forecast' => $forecastData
        ];

        // Obtener filtros para cargar drops en UI
        $listaCategorias = Capsule::table('categoria_productos')->where('empresa_id', $eId)->select('id', 'nombre')->get();
        $listaProductos = Capsule::table('productos')->where('empresa_id', $eId)->select('id', 'codigo_interno', 'nombre')->limit(500)->get();

        return $this->ok($res, [
            'metrics' => [
                'totalPicksMes'    => $totalActual,
                'crecimientoPct'   => round($crecimiento, 2),
                'bajaRotacionCount'=> $bajaRotacion->count(),
                'mesFiltro'        => $mes,
            ],
            'pickingPorCategoria' => $picksPorCategoria,
            'ventasMesAMes'       => $ventasArray,
            'bajaRotacion'        => $bajaRotacion,
            'mlForecast'          => $mlData,
            'filtros' => [
                'categorias' => $listaCategorias,
                'productos'  => $listaProductos
            ]
        ]);
    }
