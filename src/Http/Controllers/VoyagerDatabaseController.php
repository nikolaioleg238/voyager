<?php

namespace TCG\Voyager\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use TCG\Voyager\Database\DatabaseUpdater;
use TCG\Voyager\Database\Schema\Column;
use TCG\Voyager\Database\Schema\Identifier;
use TCG\Voyager\Database\Schema\SchemaManager;
use TCG\Voyager\Database\Schema\Table;
use TCG\Voyager\Database\Types\Type;
use TCG\Voyager\Events\TableAdded;
use TCG\Voyager\Events\TableDeleted;
use TCG\Voyager\Events\TableUpdated;
use TCG\Voyager\Facades\Voyager;
use Illuminate\Support\Facades\Log;

class VoyagerDatabaseController extends Controller
{
    public function index()
    {
        $this->authorize('browse_database');

        $dataTypes = Voyager::model('DataType')->select('id', 'name', 'slug')->get()->keyBy('name')->toArray();

        $tables = array_map(function ($table) use ($dataTypes) {
            $table = Str::replaceFirst(DB::getTablePrefix(), '', $table);

            $table = [
                'prefix'     => DB::getTablePrefix(),
                'name'       => $table,
                'slug'       => $dataTypes[$table]['slug'] ?? null,
                'dataTypeId' => $dataTypes[$table]['id'] ?? null,
            ];

            return (object) $table;
        }, SchemaManager::listTableNames());

        return Voyager::view('voyager::tools.database.index')->with(compact('dataTypes', 'tables'));
    }

    /**
     * Create database table.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create()
    {
        $this->authorize('browse_database');

        try {
            $db = $this->prepareDbManager('create');
        } catch (\Throwable $e) {
            Log::error('VoyagerDatabaseController::create prepareDbManager failed', [
                'action' => 'create',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with($this->alertException($e));
        }

        return Voyager::view('voyager::tools.database.edit-add', compact('db'));
    }

    /**
     * Store new database table.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $this->authorize('browse_database');

        try {
            $conn = 'database.connections.'.config('database.default');
            Type::registerCustomPlatformTypes();

            // Get the raw payload (may be JSON string or array)
            $rawTable = $request->input('table');

            // Decode JSON when necessary and validate
            if (is_string($rawTable)) {
                $decoded = json_decode($rawTable, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Invalid JSON payload for table: '.json_last_error_msg());
                }
                $tableArr = $decoded;
            } elseif (is_array($rawTable)) {
                $tableArr = $rawTable;
            } else {
                throw new \InvalidArgumentException('Missing or invalid table payload');
            }

            // Ensure options array exists
            if (!isset($tableArr['options']) || !is_array($tableArr['options'])) {
                $tableArr['options'] = [];
            }

            $tableArr['options']['collate'] = $tableArr['options']['collate'] ?? config($conn.'.collation', 'utf8mb4_unicode_ci');
            $tableArr['options']['charset'] = $tableArr['options']['charset'] ?? config($conn.'.charset', 'utf8mb4');

            // Validate minimal required keys
            if (empty($tableArr['name']) || empty($tableArr['columns']) || !is_array($tableArr['columns'])) {
                throw new \InvalidArgumentException('Table payload must include a name and an array of columns');
            }

            // Construct a TCG\Voyager Table instance (this will validate identifiers)
            $tableObj = Table::make($tableArr);

            // Delegate creation to SchemaManager which accepts Doctrine Table instances
            SchemaManager::createTable($tableObj);

            if (isset($request->create_model) && $request->create_model == 'on') {
                $modelNamespace = config('voyager.models.namespace', app()->getNamespace());
                $params = [
                    'name' => $modelNamespace.Str::studly(Str::singular($tableObj->name)),
                ];

                // if (in_array('deleted_at', $request->input('field.*'))) {
                //     $params['--softdelete'] = true;
                // }

                if (isset($request->create_migration) && $request->create_migration == 'on') {
                    $params['--migration'] = true;
                }

                Artisan::call('voyager:make:model', $params);
            } elseif (isset($request->create_migration) && $request->create_migration == 'on') {
                Artisan::call('make:migration', [
                    'name'    => 'create_'.$tableObj->name.'_table',
                    '--table' => $tableObj->name,
                ]);
            }

            event(new TableAdded($tableObj));

            return redirect()
               ->route('voyager.database.index')
               ->with($this->alertSuccess(__('voyager::database.success_create_table', ['table' => $tableObj->name])));
        } catch (Exception $e) {
            // Log the exception for debugging and return a helpful message to the user
            Log::error('VoyagerDatabaseController::store failed creating table', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->input('table'),
            ]);

            return back()->with($this->alertException($e))->withInput();
        }
    }

    /**
     * Edit database table.
     *
     * @param string $table
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function edit($table)
    {
        $this->authorize('browse_database');

        if (!SchemaManager::tableExists($table)) {
            return redirect()
                ->route('voyager.database.index')
                ->with($this->alertError(__('voyager::database.edit_table_not_exist')));
        }

        try {
            $db = $this->prepareDbManager('update', $table);
        } catch (\Throwable $e) {
            Log::error('VoyagerDatabaseController::edit prepareDbManager failed', [
                'action' => 'update',
                'table' => $table,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with($e->getMessage());
        }

        return Voyager::view('voyager::tools.database.edit-add', compact('db'));
    }

    /**
     * Update database table.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $this->authorize('browse_database');

        $table = json_decode($request->table, true);

        try {
            DatabaseUpdater::update($table);
            // TODO: synch BREAD with Table
            // $this->cleanOldAndCreateNew($request->original_name, $request->name);
            event(new TableUpdated($table));
        } catch (Exception $e) {
            return back()->with($this->alertException($e))->withInput();
        }

        return redirect()
               ->route('voyager.database.index')
               ->with($this->alertSuccess(__('voyager::database.success_create_table', ['table' => $table['name']])));
    }

    protected function prepareDbManager($action, $table = '')
    {
        $db = new \stdClass();

         // Need to get the types first to register custom types
        // Ensure custom platform types are registered before retrieving types
        Type::registerCustomPlatformTypes();
        $db->types = Type::getPlatformTypes();

        if ($action == 'update') {
            $db->table = SchemaManager::listTableDetails($table);
            $db->formAction = route('voyager.database.update', $table);
        } else {
            $db->table = new Table('New Table');

            // Add prefilled columns
            $db->table->addColumn('id', 'integer', [
                'unsigned'      => true,
                'notnull'       => true,
                'autoincrement' => true,
            ]);

            $db->table->setPrimaryKey(['id'], 'primary');

            $db->formAction = route('voyager.database.store');
        }

        $oldTable = old('table');
        $db->oldTable = $oldTable ? $oldTable : json_encode(null);
        $db->action = $action;
        $db->identifierRegex = Identifier::REGEX;
        $db->platform = DB::connection()->getDriverName();

        return $db;
    }

    public function cleanOldAndCreateNew($originalName, $tableName)
    {
        if (!empty($originalName) && $originalName != $tableName) {
            $dt = DB::table('data_types')->where('name', $originalName);
            if ($dt->get()) {
                $dt->delete();
            }

            $perm = DB::table('permissions')->where('table_name', $originalName);
            if ($perm->get()) {
                $perm->delete();
            }

            $params = ['name' => Str::studly(Str::singular($tableName))];
            Artisan::call('voyager:make:model', $params);
        }
    }

    public function reorder_column(Request $request)
    {
        $this->authorize('browse_database');

        if ($request->ajax()) {
            $table = $request->table;
            $column = $request->column;
            $after = $request->after;
            if ($after == null) {
                // SET COLUMN TO THE TOP
                DB::query("ALTER $table MyTable CHANGE COLUMN $column FIRST");
            }

            return 1;
        }

        return 0;
    }

    /**
     * Show table.
     *
     * @param string $table
     *
     * @return JSON
     */
    public function show($table)
    {
        $this->authorize('browse_database');

        $additional_attributes = [];
        $model_name = Voyager::model('DataType')->where('name', $table)->pluck('model_name')->first();
        if (isset($model_name)) {
            $model = app($model_name);
            if (isset($model->additional_attributes)) {
                foreach ($model->additional_attributes as $attribute) {
                    $additional_attributes[$attribute] = [];
                }
            }
        }

        // Ensure the described table collection is keyed by column name so merging
        // with additional attributes (an associative array) doesn't create numeric
        // key conflicts which can trigger "Undefined array key 0" notices.
        $described = collect(SchemaManager::describeTable($table))->keyBy('field')->merge($additional_attributes);

        return response()->json($described);
    }

    /**
     * Destroy table.
     *
     * @param string $table
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($table)
    {
        $this->authorize('browse_database');

        try {
            SchemaManager::dropTable($table);
            event(new TableDeleted($table));

            return redirect()
                ->route('voyager.database.index')
                ->with($this->alertSuccess(__('voyager::database.success_delete_table', ['table' => $table])));
        } catch (Exception $e) {
            return back()->with($this->alertException($e));
        }
    }
}
