<?php
/**
 * Script de Ventas PrestaShop
 * Listado de ventas por producto agrupadas por año
 */

// Configuración de la base de datos
$db_host = 'localhost';
$db_name = 'nombre_base_datos';
$db_user = 'usuario';
$db_pass = 'contraseña';
$db_prefix = 'ps_'; // Prefijo de las tablas

// Conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Obtener estados de pedidos
$stmt = $pdo->prepare("SELECT id_order_state, name FROM {$db_prefix}order_state_lang WHERE id_lang = 1");
$stmt->execute();
$order_states = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Procesar formulario
$selected_states = isset($_POST['states']) ? $_POST['states'] : [];
$search_product = isset($_POST['search_product']) ? trim($_POST['search_product']) : '';
$selected_years = isset($_POST['years']) ? $_POST['years'] : [];
$date_type = isset($_POST['date_type']) ? $_POST['date_type'] : 'last_state';
$export = isset($_POST['export']) ? true : false;

// Obtener años disponibles
$stmt_years = $pdo->prepare("SELECT DISTINCT YEAR(date_add) AS year FROM {$db_prefix}orders ORDER BY year DESC");
$stmt_years->execute();
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

// Preparar arrays para filtros
$where = [];
$params = [];

// Construir consulta SQL - SIEMPRE mostrar ambas fechas para transparencia
$sql = "SELECT
    COALESCE(NULLIF(p.reference, ''), pl.name) AS referencia,
    pl.name AS nombre_producto,
    YEAR(o.date_add) AS año_creacion,
    YEAR(oh.date_add) AS año_estado,
    " . ($date_type === 'order_date' ? 'YEAR(o.date_add)' : 'YEAR(oh.date_add)') . " AS año,
    o.date_add AS fecha_creacion_pedido,
    oh.date_add AS fecha_ultimo_estado,
    SUM(od.product_quantity) AS cantidad_vendida
FROM {$db_prefix}order_detail od
INNER JOIN {$db_prefix}orders o ON od.id_order = o.id_order
INNER JOIN {$db_prefix}product p ON od.product_id = p.id_product
INNER JOIN {$db_prefix}product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
INNER JOIN {$db_prefix}order_history oh ON oh.id_order = o.id_order";

// Subconsulta para obtener la fecha del estado actual de cada pedido
$where[] = "oh.date_add = (
    SELECT MAX(oh2.date_add)
    FROM {$db_prefix}order_history oh2
    WHERE oh2.id_order = o.id_order
    AND oh2.id_order_state = o.current_state
)";

// Filtrar por estados si están seleccionados
if (!empty($selected_states)) {
    $placeholders = implode(',', array_fill(0, count($selected_states), '?'));
    $where[] = "o.current_state IN ($placeholders)";
    $params = array_merge($params, $selected_states);
}

// Filtrar por producto si hay búsqueda
if (!empty($search_product)) {
    $where[] = "(pl.name LIKE ? OR p.reference LIKE ?)";
    $params[] = "%$search_product%";
    $params[] = "%$search_product%";
}

// Filtrar por años si están seleccionados (ahora filtra por el tipo de fecha seleccionado)
if (!empty($selected_years)) {
    $placeholders_years = implode(',', array_fill(0, count($selected_years), '?'));
    if ($date_type === 'order_date') {
        $where[] = "YEAR(o.date_add) IN ($placeholders_years)";
    } else {
        $where[] = "YEAR(oh.date_add) IN ($placeholders_years)";
    }
    $params = array_merge($params, $selected_years);
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " GROUP BY COALESCE(NULLIF(p.reference, ''), pl.name), pl.name, YEAR(o.date_add), YEAR(oh.date_add), o.date_add, oh.date_add
          ORDER BY año DESC, cantidad_vendida DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Exportar a Excel
if ($export && !empty($results)) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=ventas_productos_' . date('Y-m-d') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo "\xEF\xBB\xBF"; // BOM para UTF-8
    
    // Crear tabla HTML para Excel
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Referencia</th>';
    echo '<th>Nombre Producto</th>';
    echo '<th>Fecha Creación Pedido</th>';
    echo '<th>Fecha Último Estado</th>';
    echo '<th>Año (Agrupación)</th>';
    echo '<th>Cantidad Vendida</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($results as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['referencia']) . '</td>';
        echo '<td>' . htmlspecialchars($row['nombre_producto']) . '</td>';
        echo '<td>' . date('d/m/Y', strtotime($row['fecha_creacion_pedido'])) . '</td>';
        echo '<td>' . date('d/m/Y', strtotime($row['fecha_ultimo_estado'])) . '</td>';
        echo '<td>' . $row['año'] . '</td>';
        echo '<td>' . $row['cantidad_vendida'] . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas por Producto - PrestaShop</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #007bff;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 25px;
        }
        .filter-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .filter-group {
            flex: 1;
            min-width: 250px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .states-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .state-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .state-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .state-checkbox label {
            margin: 0;
            font-weight: normal;
            cursor: pointer;
        }
        .accordion {
            background: #fff;
            border: 2px solid #ffc107;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .accordion-header {
            background: #fff3cd;
            padding: 15px;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #856404;
            border-radius: 3px;
        }
        .accordion-header:hover {
            background: #ffe69c;
        }
        .accordion-header::before {
            content: '▶';
            transition: transform 0.3s;
            font-size: 12px;
        }
        .accordion-header.active::before {
            transform: rotate(90deg);
        }
        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            padding: 0 15px;
        }
        .accordion-content.active {
            max-height: 500px;
            padding: 15px;
            overflow-y: auto;
        }
        .years-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .year-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .year-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .year-checkbox label {
            margin: 0;
            font-weight: normal;
            cursor: pointer;
        }
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        button {
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background: #007bff;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .no-results {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }
        .total-row {
            background: #e9ecef;
            font-weight: 600;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-card {
            flex: 1;
            min-width: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            font-size: 14px;
            margin-bottom: 8px;
            opacity: 0.9;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
        }
    </style>
    <script>
        function toggleAccordion(element) {
            element.classList.toggle('active');
            var content = element.nextElementSibling;
            content.classList.toggle('active');
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>📊 Ventas por Producto</h1>
        
        <form method="POST" action="">
            <div class="filter-section">
                <h3 style="margin-bottom: 15px; color: #333;">Filtros de Búsqueda</h3>
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="search_product">🔍 Buscar Producto (nombre o referencia):</label>
                        <input type="text" id="search_product" name="search_product" 
                               placeholder="Ej: Camiseta, REF-001..." 
                               value="<?php echo htmlspecialchars($search_product); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_type">📆 Tipo de Fecha:</label>
                        <select id="date_type" name="date_type" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background: white;">
                            <option value="last_state" <?php echo $date_type === 'last_state' ? 'selected' : ''; ?>>
                                Fecha del Último Estado (cuándo alcanzó el estado actual)
                            </option>
                            <option value="order_date" <?php echo $date_type === 'order_date' ? 'selected' : ''; ?>>
                                Fecha de Creación del Pedido (cuándo se creó)
                            </option>
                        </select>
                        <div style="margin-top: 8px; padding: 10px; background: #e3f2fd; border-left: 3px solid #2196f3; font-size: 12px; border-radius: 3px;">
                            <strong>💡 Nota:</strong> Un pedido creado en 2024 puede cambiar a estado "cancelado" en 2025.
                            Por eso, el mismo pedido puede aparecer en diferentes años según el tipo de fecha seleccionado.
                            <span style="color: #dc3545; font-weight: bold;"> ⚠️</span> indica cuando las fechas están en años diferentes.
                        </div>
                    </div>
                </div>
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label>📅 Filtrar por Años:</label>
                        <div class="years-grid">
                            <?php foreach ($available_years as $year): ?>
                                <div class="year-checkbox">
                                    <input type="checkbox" 
                                           id="year_<?php echo $year; ?>" 
                                           name="years[]" 
                                           value="<?php echo $year; ?>"
                                           <?php echo in_array($year, $selected_years) ? 'checked' : ''; ?>>
                                    <label for="year_<?php echo $year; ?>">
                                        <?php echo $year; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="accordion">
                    <div class="accordion-header" onclick="toggleAccordion(this)">
                        ⚠️ IMPORTANTE: Selecciona los Estados de Pedidos
                    </div>
                    <div class="accordion-content">
                        <div class="states-grid">
                            <?php foreach ($order_states as $state): ?>
                                <div class="state-checkbox">
                                    <input type="checkbox" 
                                           id="state_<?php echo $state['id_order_state']; ?>" 
                                           name="states[]" 
                                           value="<?php echo $state['id_order_state']; ?>"
                                           <?php echo in_array($state['id_order_state'], $selected_states) ? 'checked' : ''; ?>>
                                    <label for="state_<?php echo $state['id_order_state']; ?>">
                                        <?php echo htmlspecialchars($state['name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn-primary">🔍 Buscar</button>
                    <button type="submit" name="export" value="1" class="btn-success">📥 Exportar a Excel</button>
                    <button type="button" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>'" class="btn-secondary">🔄 Limpiar</button>
                </div>
            </div>
        </form>
        
        <?php if (!empty($results)): ?>
            <?php
            // Calcular estadísticas
            $total_productos = count($results);
            $total_cantidad = array_sum(array_column($results, 'cantidad_vendida'));
            $años = array_unique(array_column($results, 'año'));
            ?>
            
            <div class="stats">
                <div class="stat-card">
                    <h3>Total Productos</h3>
                    <div class="value"><?php echo $total_productos; ?></div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h3>Unidades Vendidas</h3>
                    <div class="value"><?php echo number_format($total_cantidad, 0, ',', '.'); ?></div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <h3>Años Analizados</h3>
                    <div class="value"><?php echo count($años); ?></div>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Referencia</th>
                        <th>Nombre del Producto</th>
                        <th>Fecha Creación Pedido</th>
                        <th>Fecha Último Estado</th>
                        <th>Año (Agrupación <?php echo $date_type === 'order_date' ? 'Creación' : 'Estado'; ?>)</th>
                        <th>Cantidad Vendida</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($row['referencia']); ?></strong>
                                <?php if ($row['referencia'] == $row['nombre_producto']): ?>
                                    <span style="color: #dc3545; font-size: 11px;"> (sin ref.)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['nombre_producto']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($row['fecha_creacion_pedido'])); ?></td>
                            <td>
                                <?php echo date('d/m/Y', strtotime($row['fecha_ultimo_estado'])); ?>
                                <?php if ($row['año_creacion'] != $row['año_estado']): ?>
                                    <span style="color: #dc3545; font-weight: bold;"> ⚠️</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo $row['año']; ?></strong></td>
                            <td><strong><?php echo number_format($row['cantidad_vendida'], 0, ',', '.'); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5"><strong>TOTAL</strong></td>
                        <td><strong><?php echo number_format($total_cantidad, 0, ',', '.'); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="no-results">
                ❌ No se encontraron resultados con los filtros aplicados
            </div>
        <?php else: ?>
            <div class="no-results">
                👆 Selecciona los estados de pedidos y/o busca un producto para ver los resultados
            </div>
        <?php endif; ?>
    </div>
</body>
</html>