<?php
/**
 * Script de Ventas PrestaShop
 * Listado de ventas por producto agrupadas por año
 * Compatible con PrestaShop 1.6 y PrestaShop 8+
 */

// Función para detectar y cargar configuración de PrestaShop automáticamente
function loadPrestaShopConfig() {
    $config = [
        'db_host' => 'localhost',
        'db_name' => null,
        'db_user' => null,
        'db_pass' => null,
        'db_prefix' => 'ps_',
        'ps_version' => 'desconocida',
        'config_found' => false
    ];

    // Intentar detectar la raíz de PrestaShop
    // El script puede estar en la raíz o en un subdirectorio
    $possible_paths = [
        __DIR__,                    // Mismo directorio que el script
        dirname(__DIR__),           // Un nivel arriba
        dirname(dirname(__DIR__))   // Dos niveles arriba
    ];

    foreach ($possible_paths as $base_path) {
        // PrestaShop 8+ (app/config/parameters.php)
        $ps8_config = $base_path . '/app/config/parameters.php';
        if (file_exists($ps8_config)) {
            $parameters = require $ps8_config;

            if (isset($parameters['parameters'])) {
                $params = $parameters['parameters'];
                $config['db_host'] = $params['database_host'] ?? 'localhost';
                $config['db_name'] = $params['database_name'] ?? null;
                $config['db_user'] = $params['database_user'] ?? null;
                $config['db_pass'] = $params['database_password'] ?? null;
                $config['db_prefix'] = $params['database_prefix'] ?? 'ps_';
                $config['ps_version'] = '8+';
                $config['config_found'] = true;
                $config['config_path'] = $ps8_config;
                break;
            }
        }

        // PrestaShop 1.6 (config/settings.inc.php)
        $ps16_config = $base_path . '/config/settings.inc.php';
        if (file_exists($ps16_config)) {
            // Incluir el archivo para cargar las constantes
            require_once $ps16_config;

            if (defined('_DB_SERVER_')) {
                $config['db_host'] = _DB_SERVER_;
                $config['db_name'] = _DB_NAME_;
                $config['db_user'] = _DB_USER_;
                $config['db_pass'] = _DB_PASSWD_;
                $config['db_prefix'] = _DB_PREFIX_;
                $config['ps_version'] = '1.6';
                $config['config_found'] = true;
                $config['config_path'] = $ps16_config;
                break;
            }
        }
    }

    return $config;
}

// Cargar configuración automáticamente
$ps_config = loadPrestaShopConfig();

if (!$ps_config['config_found']) {
    die('
    <div style="font-family: Arial; padding: 20px; max-width: 800px; margin: 50px auto; border: 2px solid #dc3545; border-radius: 8px; background: #f8d7da;">
        <h2 style="color: #721c24; margin-top: 0;">❌ Error: No se encontró la configuración de PrestaShop</h2>
        <p style="color: #721c24;">
            No se pudo detectar automáticamente la configuración de PrestaShop.
            Asegúrate de que este script esté en la carpeta raíz de PrestaShop o en un subdirectorio.
        </p>
        <h3 style="color: #721c24;">Ubicaciones buscadas:</h3>
        <ul style="color: #721c24;">
            <li><code>./app/config/parameters.php</code> (PrestaShop 8+)</li>
            <li><code>./config/settings.inc.php</code> (PrestaShop 1.6)</li>
            <li><code>../app/config/parameters.php</code></li>
            <li><code>../config/settings.inc.php</code></li>
            <li><code>../../app/config/parameters.php</code></li>
            <li><code>../../config/settings.inc.php</code></li>
        </ul>
        <h3 style="color: #721c24;">Solución manual:</h3>
        <p style="color: #721c24;">
            Si necesitas configurar manualmente, edita este archivo y reemplaza la función <code>loadPrestaShopConfig()</code>
            con las credenciales de tu base de datos.
        </p>
    </div>
    ');
}

// Extraer configuración
$db_host = $ps_config['db_host'];
$db_name = $ps_config['db_name'];
$db_user = $ps_config['db_user'];
$db_pass = $ps_config['db_pass'];
$db_prefix = $ps_config['db_prefix'];

// Conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('
    <div style="font-family: Arial; padding: 20px; max-width: 800px; margin: 50px auto; border: 2px solid #dc3545; border-radius: 8px; background: #f8d7da;">
        <h2 style="color: #721c24; margin-top: 0;">❌ Error de conexión a la base de datos</h2>
        <p style="color: #721c24;"><strong>Mensaje:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
        <h3 style="color: #721c24;">Configuración detectada:</h3>
        <ul style="color: #721c24;">
            <li><strong>Versión PrestaShop:</strong> ' . htmlspecialchars($ps_config['ps_version']) . '</li>
            <li><strong>Archivo de configuración:</strong> ' . htmlspecialchars($ps_config['config_path'] ?? 'N/A') . '</li>
            <li><strong>Host:</strong> ' . htmlspecialchars($db_host) . '</li>
            <li><strong>Base de datos:</strong> ' . htmlspecialchars($db_name) . '</li>
            <li><strong>Usuario:</strong> ' . htmlspecialchars($db_user) . '</li>
            <li><strong>Prefijo:</strong> ' . htmlspecialchars($db_prefix) . '</li>
        </ul>
    </div>
    ');
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
$view_mode = isset($_POST['view_mode']) ? $_POST['view_mode'] : 'grouped'; // 'grouped' o 'detailed'
$export = isset($_POST['export']) ? true : false;

// Paginación y ordenamiento
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = isset($_POST['limit']) ? intval($_POST['limit']) : 100;
$order_by = isset($_GET['order_by']) ? $_GET['order_by'] : '';
$order_dir = isset($_GET['order_dir']) && $_GET['order_dir'] === 'DESC' ? 'DESC' : 'ASC';

// Validar límite
if (!in_array($limit, [50, 100, 200, 500])) {
    $limit = 100;
}

$offset = ($page - 1) * $limit;

// Obtener años disponibles
$stmt_years = $pdo->prepare("SELECT DISTINCT YEAR(date_add) AS year FROM {$db_prefix}orders ORDER BY year DESC");
$stmt_years->execute();
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

// Preparar arrays para filtros
$where = [];
$params = [];

// Construir la base común de las consultas
$base_from = "FROM {$db_prefix}order_detail od
    INNER JOIN {$db_prefix}orders o ON od.id_order = o.id_order
    INNER JOIN {$db_prefix}product p ON od.product_id = p.id_product
    INNER JOIN {$db_prefix}product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
    INNER JOIN {$db_prefix}order_history oh ON oh.id_order = o.id_order";

if ($view_mode === 'detailed') {
    $base_from .= " LEFT JOIN {$db_prefix}order_state_lang osl ON o.current_state = osl.id_order_state AND osl.id_lang = 1";
}

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

// Filtrar por años si están seleccionados
if (!empty($selected_years)) {
    $placeholders_years = implode(',', array_fill(0, count($selected_years), '?'));
    if ($date_type === 'order_date') {
        $where[] = "YEAR(o.date_add) IN ($placeholders_years)";
    } else {
        $where[] = "YEAR(oh.date_add) IN ($placeholders_years)";
    }
    $params = array_merge($params, $selected_years);
}

$where_clause = !empty($where) ? " WHERE " . implode(' AND ', $where) : "";

// Construir consulta SQL según el modo de vista
if ($view_mode === 'detailed') {
    // VISTA DETALLADA: Muestra cada pedido individual con su ID

    // Primero contar total de registros
    $sql_count = "SELECT COUNT(*) as total
        $base_from
        $where_clause";

    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];

    // Consulta principal con paginación
    $sql = "SELECT
        o.id_order,
        COALESCE(NULLIF(p.reference, ''), pl.name) AS referencia,
        pl.name AS nombre_producto,
        YEAR(o.date_add) AS año_creacion,
        YEAR(oh.date_add) AS año_estado,
        " . ($date_type === 'order_date' ? 'YEAR(o.date_add)' : 'YEAR(oh.date_add)') . " AS año,
        o.date_add AS fecha_creacion_pedido,
        oh.date_add AS fecha_ultimo_estado,
        od.product_quantity AS cantidad_vendida,
        osl.name AS estado_pedido
    $base_from
    $where_clause";

    // Ordenamiento dinámico
    $valid_columns_detailed = ['id_order' => 'o.id_order', 'referencia' => 'referencia', 'nombre_producto' => 'pl.name',
                                'fecha_creacion_pedido' => 'o.date_add', 'fecha_ultimo_estado' => 'oh.date_add',
                                'año' => 'año', 'cantidad_vendida' => 'od.product_quantity', 'estado_pedido' => 'osl.name'];

    if (!empty($order_by) && isset($valid_columns_detailed[$order_by])) {
        $sql .= " ORDER BY " . $valid_columns_detailed[$order_by] . " $order_dir";
    } else {
        $sql .= " ORDER BY o.id_order DESC";
    }

    $sql .= " LIMIT $limit OFFSET $offset";

} else {
    // VISTA AGRUPADA: Agrupa por producto y año

    // Primero contar total de registros (grupos)
    $sql_count = "SELECT COUNT(*) as total FROM (
        SELECT
            COALESCE(NULLIF(p.reference, ''), pl.name) AS referencia,
            " . ($date_type === 'order_date' ? 'YEAR(o.date_add)' : 'YEAR(oh.date_add)') . " AS año
        $base_from
        $where_clause
        GROUP BY COALESCE(NULLIF(p.reference, ''), pl.name), pl.name, año
    ) AS subquery";

    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];

    // Consulta principal con paginación
    $sql = "SELECT
        COALESCE(NULLIF(p.reference, ''), pl.name) AS referencia,
        pl.name AS nombre_producto,
        " . ($date_type === 'order_date' ? 'YEAR(o.date_add)' : 'YEAR(oh.date_add)') . " AS año,
        SUM(od.product_quantity) AS cantidad_vendida
    $base_from
    $where_clause
    GROUP BY COALESCE(NULLIF(p.reference, ''), pl.name), pl.name, año";

    // Ordenamiento dinámico
    $valid_columns_grouped = ['referencia' => 'referencia', 'nombre_producto' => 'nombre_producto',
                              'año' => 'año', 'cantidad_vendida' => 'cantidad_vendida'];

    if (!empty($order_by) && isset($valid_columns_grouped[$order_by])) {
        $sql .= " ORDER BY " . $valid_columns_grouped[$order_by] . " $order_dir";
    } else {
        $sql .= " ORDER BY año DESC, cantidad_vendida DESC";
    }

    $sql .= " LIMIT $limit OFFSET $offset";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular total de páginas
$total_pages = ceil($total_records / $limit);

// Función para generar URL de ordenamiento
function getSortUrl($column) {
    global $order_by, $order_dir;
    $params = $_GET;
    $params['order_by'] = $column;

    // Si ya está ordenando por esta columna, invertir la dirección
    if ($order_by === $column) {
        $params['order_dir'] = ($order_dir === 'ASC') ? 'DESC' : 'ASC';
    } else {
        $params['order_dir'] = 'ASC';
    }

    return '?' . http_build_query($params);
}

// Función para obtener el icono de ordenamiento
function getSortIcon($column) {
    global $order_by, $order_dir;
    if ($order_by === $column) {
        return $order_dir === 'ASC' ? ' ▲' : ' ▼';
    }
    return ' ⇅';
}

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
    if ($view_mode === 'detailed') {
        echo '<th>ID Pedido</th>';
        echo '<th>Referencia</th>';
        echo '<th>Nombre Producto</th>';
        echo '<th>Fecha Creación Pedido</th>';
        echo '<th>Fecha Último Estado</th>';
        echo '<th>Estado</th>';
        echo '<th>Año (' . ($date_type === 'order_date' ? 'Creación' : 'Estado') . ')</th>';
        echo '<th>Cantidad</th>';
    } else {
        echo '<th>Referencia</th>';
        echo '<th>Nombre Producto</th>';
        echo '<th>Año (' . ($date_type === 'order_date' ? 'Creación' : 'Estado') . ')</th>';
        echo '<th>Cantidad Total</th>';
    }
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($results as $row) {
        echo '<tr>';
        if ($view_mode === 'detailed') {
            echo '<td>' . $row['id_order'] . '</td>';
            echo '<td>' . htmlspecialchars($row['referencia']) . '</td>';
            echo '<td>' . htmlspecialchars($row['nombre_producto']) . '</td>';
            echo '<td>' . date('d/m/Y H:i', strtotime($row['fecha_creacion_pedido'])) . '</td>';
            echo '<td>' . date('d/m/Y H:i', strtotime($row['fecha_ultimo_estado'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['estado_pedido']) . '</td>';
            echo '<td>' . $row['año'] . '</td>';
            echo '<td>' . $row['cantidad_vendida'] . '</td>';
        } else {
            echo '<td>' . htmlspecialchars($row['referencia']) . '</td>';
            echo '<td>' . htmlspecialchars($row['nombre_producto']) . '</td>';
            echo '<td>' . $row['año'] . '</td>';
            echo '<td>' . $row['cantidad_vendida'] . '</td>';
        }
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
        th a {
            color: white;
            text-decoration: none;
            display: block;
            width: 100%;
            transition: opacity 0.2s;
        }
        th a:hover {
            opacity: 0.8;
        }
        th.sortable {
            cursor: pointer;
            user-select: none;
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
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .pagination-info {
            color: #555;
            font-size: 14px;
        }
        .pagination {
            display: flex;
            gap: 5px;
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #007bff;
            background: white;
            transition: all 0.2s;
        }
        .pagination a:hover {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        .pagination .current {
            background: #007bff;
            color: white;
            border-color: #007bff;
            font-weight: bold;
        }
        .pagination .disabled {
            color: #999;
            cursor: not-allowed;
            background: #f8f9fa;
        }
        .pagination .disabled:hover {
            background: #f8f9fa;
            color: #999;
            border-color: #ddd;
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
        <h1>📊 Ventas por Producto
            <span style="font-size: 14px; background: #28a745; color: white; padding: 5px 12px; border-radius: 4px; margin-left: 10px; vertical-align: middle; font-weight: normal;">
                ✓ PrestaShop <?php echo htmlspecialchars($ps_config['ps_version']); ?> detectado
            </span>
        </h1>

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

                <div class="filter-row" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #ddd;">
                    <div class="filter-group">
                        <label for="view_mode">📋 Modo de Vista:</label>
                        <select id="view_mode" name="view_mode" style="width: 100%; padding: 10px; border: 2px solid #007bff; border-radius: 4px; font-size: 14px; background: white; font-weight: 600;">
                            <option value="grouped" <?php echo $view_mode === 'grouped' ? 'selected' : ''; ?>>
                                📊 Vista Agrupada por Año (suma total por producto y año)
                            </option>
                            <option value="detailed" <?php echo $view_mode === 'detailed' ? 'selected' : ''; ?>>
                                🔍 Vista Detallada por Pedido (muestra ID de cada pedido)
                            </option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="limit">📄 Resultados por Página:</label>
                        <select id="limit" name="limit" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background: white;">
                            <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 resultados</option>
                            <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 resultados</option>
                            <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200 resultados</option>
                            <option value="500" <?php echo $limit == 500 ? 'selected' : ''; ?>>500 resultados</option>
                        </select>
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
            // Calcular estadísticas de la página actual
            $total_productos_pagina = count($results);
            $total_cantidad_pagina = array_sum(array_column($results, 'cantidad_vendida'));
            $años = array_unique(array_column($results, 'año'));

            // Calcular totales generales (todas las páginas)
            $sql_totals = "SELECT
                COUNT(" . ($view_mode === 'detailed' ? '*' : 'DISTINCT COALESCE(NULLIF(p.reference, \'\'), pl.name)') . ") as total_productos,
                SUM(od.product_quantity) as total_cantidad
            $base_from
            $where_clause";

            if ($view_mode === 'grouped') {
                // Para vista agrupada, necesitamos contar grupos únicos
                $sql_totals = "SELECT
                    COUNT(DISTINCT CONCAT(COALESCE(NULLIF(p.reference, ''), pl.name), '-', " . ($date_type === 'order_date' ? 'YEAR(o.date_add)' : 'YEAR(oh.date_add)') . ")) as total_productos,
                    SUM(od.product_quantity) as total_cantidad
                $base_from
                $where_clause";
            }

            $stmt_totals = $pdo->prepare($sql_totals);
            $stmt_totals->execute($params);
            $totals = $stmt_totals->fetch(PDO::FETCH_ASSOC);
            $total_cantidad = $totals['total_cantidad'];
            ?>
            
            <div class="stats">
                <div class="stat-card">
                    <h3>Total Registros</h3>
                    <div class="value"><?php echo number_format($total_records, 0, ',', '.'); ?></div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 5px;">
                        (<?php echo $total_productos_pagina; ?> en esta página)
                    </div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h3>Unidades Vendidas (Total)</h3>
                    <div class="value"><?php echo number_format($total_cantidad, 0, ',', '.'); ?></div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 5px;">
                        (<?php echo number_format($total_cantidad_pagina, 0, ',', '.'); ?> en esta página)
                    </div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <h3>Página Actual</h3>
                    <div class="value"><?php echo $page; ?> / <?php echo $total_pages; ?></div>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 5px;">
                        <?php echo $limit; ?> resultados por página
                    </div>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <?php if ($view_mode === 'detailed'): ?>
                            <th class="sortable"><a href="<?php echo getSortUrl('id_order'); ?>">ID Pedido<?php echo getSortIcon('id_order'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('referencia'); ?>">Referencia<?php echo getSortIcon('referencia'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('nombre_producto'); ?>">Nombre del Producto<?php echo getSortIcon('nombre_producto'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('fecha_creacion_pedido'); ?>">Fecha Creación<?php echo getSortIcon('fecha_creacion_pedido'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('fecha_ultimo_estado'); ?>">Fecha Último Estado<?php echo getSortIcon('fecha_ultimo_estado'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('estado_pedido'); ?>">Estado<?php echo getSortIcon('estado_pedido'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('año'); ?>">Año (<?php echo $date_type === 'order_date' ? 'Creación' : 'Estado'; ?>)<?php echo getSortIcon('año'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('cantidad_vendida'); ?>">Cantidad<?php echo getSortIcon('cantidad_vendida'); ?></a></th>
                        <?php else: ?>
                            <th class="sortable"><a href="<?php echo getSortUrl('referencia'); ?>">Referencia<?php echo getSortIcon('referencia'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('nombre_producto'); ?>">Nombre del Producto<?php echo getSortIcon('nombre_producto'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('año'); ?>">Año (<?php echo $date_type === 'order_date' ? 'Creación' : 'Estado'; ?>)<?php echo getSortIcon('año'); ?></a></th>
                            <th class="sortable"><a href="<?php echo getSortUrl('cantidad_vendida'); ?>">Cantidad Total<?php echo getSortIcon('cantidad_vendida'); ?></a></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <?php if ($view_mode === 'detailed'): ?>
                                <td><strong style="color: #007bff;"><?php echo $row['id_order']; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['referencia']); ?></strong>
                                    <?php if ($row['referencia'] == $row['nombre_producto']): ?>
                                        <span style="color: #dc3545; font-size: 11px;"> (sin ref.)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['nombre_producto']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($row['fecha_creacion_pedido'])); ?></td>
                                <td>
                                    <?php echo date('d/m/Y H:i', strtotime($row['fecha_ultimo_estado'])); ?>
                                    <?php if ($row['año_creacion'] != $row['año_estado']): ?>
                                        <span style="color: #dc3545; font-weight: bold;"> ⚠️</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['estado_pedido']); ?></td>
                                <td><strong><?php echo $row['año']; ?></strong></td>
                                <td><strong><?php echo number_format($row['cantidad_vendida'], 0, ',', '.'); ?></strong></td>
                            <?php else: ?>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['referencia']); ?></strong>
                                    <?php if ($row['referencia'] == $row['nombre_producto']): ?>
                                        <span style="color: #dc3545; font-size: 11px;"> (sin ref.)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['nombre_producto']); ?></td>
                                <td><strong><?php echo $row['año']; ?></strong></td>
                                <td><strong><?php echo number_format($row['cantidad_vendida'], 0, ',', '.'); ?></strong></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="<?php echo $view_mode === 'detailed' ? '7' : '3'; ?>">
                            <strong>TOTAL DE ESTA PÁGINA</strong>
                            <span style="font-size: 11px; font-weight: normal; margin-left: 10px;">
                                (Total general: <?php echo number_format($total_cantidad, 0, ',', '.'); ?> unidades)
                            </span>
                        </td>
                        <td><strong><?php echo number_format($total_cantidad_pagina, 0, ',', '.'); ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        <strong>Mostrando <?php echo number_format(($page - 1) * $limit + 1, 0, ',', '.'); ?> - <?php echo number_format(min($page * $limit, $total_records), 0, ',', '.'); ?></strong>
                        de <strong><?php echo number_format($total_records, 0, ',', '.'); ?></strong> resultados
                        (Página <?php echo $page; ?> de <?php echo $total_pages; ?>)
                    </div>
                    <div class="pagination">
                        <?php
                        // Construir parámetros para paginación
                        $pagination_params = $_GET;

                        // Botón anterior
                        if ($page > 1):
                            $pagination_params['page'] = $page - 1;
                        ?>
                            <a href="?<?php echo http_build_query($pagination_params); ?>">&laquo; Anterior</a>
                        <?php else: ?>
                            <span class="disabled">&laquo; Anterior</span>
                        <?php endif; ?>

                        <?php
                        // Calcular rango de páginas a mostrar
                        $range = 2; // Páginas antes y después de la actual
                        $start = max(1, $page - $range);
                        $end = min($total_pages, $page + $range);

                        // Primera página
                        if ($start > 1):
                            $pagination_params['page'] = 1;
                        ?>
                            <a href="?<?php echo http_build_query($pagination_params); ?>">1</a>
                            <?php if ($start > 2): ?>
                                <span class="disabled">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php
                        // Páginas intermedias
                        for ($i = $start; $i <= $end; $i++):
                            if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else:
                                $pagination_params['page'] = $i;
                            ?>
                                <a href="?<?php echo http_build_query($pagination_params); ?>"><?php echo $i; ?></a>
                            <?php endif;
                        endfor; ?>

                        <?php
                        // Última página
                        if ($end < $total_pages):
                            if ($end < $total_pages - 1): ?>
                                <span class="disabled">...</span>
                            <?php endif;
                            $pagination_params['page'] = $total_pages;
                        ?>
                            <a href="?<?php echo http_build_query($pagination_params); ?>"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php
                        // Botón siguiente
                        if ($page < $total_pages):
                            $pagination_params['page'] = $page + 1;
                        ?>
                            <a href="?<?php echo http_build_query($pagination_params); ?>">Siguiente &raquo;</a>
                        <?php else: ?>
                            <span class="disabled">Siguiente &raquo;</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

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