<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <title>Transferencias Pendientes</title>
        <style>
                .text-center {
                    text-align: center;
                }
                .text-right {
                    text-align: right;
                }
                body {
                    font-size: 11px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .header-bottom {
                    padding-bottom: 12px;
                }
                .bordered {
                    border: 1px solid black;
                }
                .bordered-no-side {
                    border-top: 1px solid black;
                    border-bottom: 1px solid black;
                }
                .bordered-no-left {
                    border-right: 1px solid black;
                }
                .bordered-no-right {
                    border-left: 1px solid black;
                }
                .separator {
                    font-size: 0px;
                    line-height: 0px;
                    height: 3px;
                }
                .separator2 {
                    font-size: 0px;
                    line-height: 0px;
                    background-color: #DBDBDB;
                    height: 1px;
                }

        </style>
    </head>
    <body>
        <table class="table-content">
            <tbody>
                <tr> <!-- === HEADER === -->
                    <td class="table-data-content">
                        <table class="table-content" style="table-layout: fixed;">
                            <tbody>
                                <tr>
                                    <td colspan="2" class="table-data-content">
                                        <img style="display: block; font-family: Arial, sans-serif; font-size: 15px; line-height: 18px; color: #30373b; font-weight: bold;" src="https://xxx.png" alt="myPOSLogo" height="50" />
                                    </td>
                                    <td colspan="4" style=" text-align: right;">
                                        <p><strong>{{$fecha_hora }}</strong></p>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="6" class="separator">
                                        &nbsp;
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="3" class="text-content" style="font-size: 14px; text-transform: uppercase; text-align: left; font-weight: bold;">
                                        {{ 'Tienda Origen: '.$store_origin->name }}
                                    </td>
                                    <td colspan="3" class="text-content" style="font-size: 14px; text-transform: uppercase; text-align: right; font-weight: bold;">
                                        {{ 'Tienda Destino: '.$store_destination->name }}
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="6" class="separator">
                                        &nbsp;
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="3" class="text-content" style="font-size: 10px; text-transform: uppercase; text-align: left;">
                                        {{ 'Ciudad: '.$store_city_origin  }}
                                    </td>
                                    <td colspan="3"  class="text-content" style="font-size: 10px; text-transform: uppercase; text-align: right;">
                                        {{ 'Ciudad: '.$store_city_destination  }}
                                    </td>
                                   
                                </tr>
                                <tr>
                                    <td colspan="3"  class="text-content" style="font-size: 10px; text-transform: uppercase; text-align: left;">
                                        {{ 'Dirección: '.$store_address_origin  }}
                                    </td>
                                    
                                    <td colspan="3" class="text-content" style="font-size: 10px; text-transform: uppercase; text-align: right;">
                                        {{ 'Dirección: '.$store_address_destination }}
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="3"  class="text-content" style="font-size: 10px; text-transform: uppercase; text-align: left;">
                                        {{ 'Teléfono: '.$store_telf_origin  }}
                                    </td>
                                    <td colspan="3"  class="text-content" style="font-size: 10px; text-transform: uppercase; text-align: right;">
                                        {{ 'Teléfono: '.$store_telf_destination  }}
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="6" class="separator2">
                                        &nbsp;
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <tr>
                    <table>
                        <tbody>
                                <tr>
                                    <th>Código</th>
                                    <th align="center">Descripción</th>
                                    <th align="center">Unidad</th>
                                    <th align="right">Cantidad</th>
                                    <th align="right">Precio Unitario</th>
                                    <th align="right">Importe</th>
                                </tr>
                                @forelse ($dataFinal as $dato)
                                    <tr>
                                        <td>{{$dato->codigo}}</td>
                                        <td align="center">{{$dato->descripcion}}</td>
                                        <td align="center">{{$dato->unidad}}</td>
                                        <td align="right">{{$dato->cantidad}}</td>
                                        <td align="right">{{$dato->costo}}</td>
                                        <td align="right">{{$dato->importe}}</td>
                                    </tr>
                                @empty
                                <tr>
                                    <td colspan="6" align="center">
                                        <p><strong>No existen transferencias pendientes</strong></p>
                                    </td> 
                                </tr>
                                @endforelse
                                <tr>
                                    <td colspan="6" class="separator2">
                                        &nbsp;
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="font-size: 12px; text-transform: uppercase; font-weight: bold;">
                                        {{'Cantidad Total: '.$cantidad_total}}
                                    </td>
                                    <td colspan="2" style="font-size: 12px; text-transform: uppercase; font-weight: bold;">
                                        {{'Moneda: '. $store_origin->currency}}
                                    </td>
                                    <td align="right" colspan="2" style="font-size: 12px; text-transform: uppercase; font-weight: bold;">
                                        {{'Total Importe: '. $total_importe}}
                                    </td>
                                </tr>
                        </tbody>
                        
                    </table>
    
                </tr>
                
                <tr>
                    <td class="separator">
                        &nbsp;
                    </td>
                </tr>
            </tbody>
        </table>

    </body>
</html>