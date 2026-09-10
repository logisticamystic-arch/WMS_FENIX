<?php
$cajasReq = 12;
$saldosReq = 23.982;
$upc = 48;
$cantidadReq = ($cajasReq * $upc) + $saldosReq;

$invOrigen_cantidad_cajas = 12;
$invOrigen_saldos = "23.982";
$invOrigen_cantidad = "599.982";

$cajasMove = $cajasReq;
$saldosMove = $saldosReq;
$cantidad = $cantidadReq;

var_dump($cajasMove > $invOrigen_cantidad_cajas);
var_dump($saldosMove > $invOrigen_saldos);
var_dump($cantidad > $invOrigen_cantidad);

$cajasMoveFloat = (float)$cajasMove;
$saldosMoveFloat = (float)$saldosMove;
$cantidadFloat = (float)$cantidad;
var_dump($saldosMoveFloat > (float)$invOrigen_saldos);
