@extends('voyager::master')
@if($db->action == 'update')
    @section('page_title', __('voyager::database.editing_table', ['table' => $db->table->name]))
@else
    @section('page_title', __('voyager::database.create_new_table'))
@endif

@section('page_header')
    <h1 class="page-title">
        <i class="voyager-data"></i>
        @if($db->action == 'update')
            {{ __('voyager::database.editing_table', ['table' => $db->table->name]) }}
        @else
            {{ __('voyager::database.create_new_table') }}
        @endif
    </h1>
@stop

@section('breadcrumbs')
<ol class="breadcrumb hidden-xs">
    <li>
        <a href="{{ route('voyager.dashboard')}}"><i class="voyager-boat"></i> {{ __('voyager::generic.dashboard') }}</a>
    </li>
    <li>
        <a href="{{ route('voyager.database.index') }}">
            {{ __('voyager::generic.database') }}
        </a>
    </li>

    @if($db->action == 'update')
    <li class="active">{{ __('voyager::generic.edit') }}</li>
    <li class="active">{{ $db->table->name }}</li>
    @else
    <li class="active">{{ __('voyager::generic.add') }}</li>
    @endif
</ol>
@endsection

@section('content')

    <div class="page-content container-fluid">
        <div class="row">
            <div id="dbManager" class="col-md-12">
                <form ref="form" @submit.prevent="stringifyTable" @keydown.enter.prevent action="{{ $db->formAction }}" method="POST">
                    @if($db->action == 'update'){{ method_field('PUT') }}@endif

                    <database-table-editor :table="table"></database-table-editor>

                    <input type="hidden" :value="tableJson" name="table">

                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                </form>
            </div>
        </div>
    </div>

@stop

@section('javascript')
    @include('voyager::tools.database.vue-components.database-table-editor')

    <script>
        new Vue({
            el: '#dbManager',
            data: {
                table: {},
                // Original table structure (as JSON object)
                originalTable: {!! json_encode($db->table->toArray()) !!}, // to do comparison later?
                // oldTable is stored as a JSON string in the controller; if present, emit it directly, otherwise null
                oldTable: {!! $db->oldTable ? $db->oldTable : 'null' !!},
                tableJson: ''
            },
            created() {
                // Defensive normalization: ensure originalTable/oldTable are JS objects and normalize column shapes
                function parseIfString(val) {
                    // If value is a JSON string inside quotes, try parsing; otherwise return value as-is
                    if (typeof val === 'string') {
                        try {
                            return JSON.parse(val);
                        } catch (e) {
                            return val;
                        }
                    }
                    return val;
                }

                function normalizeColumn(column) {
                    // Ensure type is an object with a name
                    if (!column.type) {
                        column.type = { name: '' };
                    } else if (typeof column.type === 'string') {
                        column.type = { name: column.type };
                    } else if (typeof column.type === 'object' && column.type.name === undefined && column.type.type !== undefined) {
                        // Some shapes use 'type' instead of 'name'
                        column.type.name = column.type.type;
                    }

                    // Try to normalize the type name to one of the platform database types so the <select> has a matching option
                    try {
                        var dbTypes = (typeof databaseTypes !== 'undefined') ? databaseTypes : null;
                        if (dbTypes && column.type && column.type.name) {
                            var nm = (column.type.name || '').toLowerCase().trim();
                            // 1) exact match
                            var found = null;
                            for (var category in dbTypes) {
                                if (!Object.prototype.hasOwnProperty.call(dbTypes, category)) continue;
                                var types = dbTypes[category] || [];
                                for (var t = 0; t < types.length; t++) {
                                    var candidate = (types[t].name || '').toLowerCase();
                                    if (candidate === nm) {
                                        found = types[t].name;
                                        break;
                                    }
                                }
                                if (found) break;
                            }

                            // 2) substring heuristics
                            if (!found) {
                                // integer-like
                                if (nm.indexOf('int') !== -1 || nm === 'integer') {
                                    for (var category in dbTypes) {
                                        var types = dbTypes[category] || [];
                                        for (var t = 0; t < types.length; t++) {
                                            var candidate = (types[t].name || '').toLowerCase();
                                            if (candidate.indexOf('int') !== -1 || candidate.indexOf('serial') !== -1 || candidate.indexOf('bigint') !== -1) {
                                                found = types[t].name; break;
                                            }
                                        }
                                        if (found) break;
                                    }
                                }
                            }

                            // datetime-like
                            if (!found && (nm.indexOf('time') !== -1 || nm.indexOf('date') !== -1 || nm === 'timestamp' || nm === 'datetime')) {
                                for (var category in dbTypes) {
                                    var types = dbTypes[category] || [];
                                    for (var t = 0; t < types.length; t++) {
                                        var candidate = (types[t].name || '').toLowerCase();
                                        if (candidate.indexOf('timestamp') !== -1 || candidate.indexOf('datetime') !== -1 || candidate.indexOf('time') !== -1) {
                                            found = types[t].name; break;
                                        }
                                    }
                                    if (found) break;
                                }
                            }

                            // numeric/text fallbacks
                            if (!found) {
                                for (var category in dbTypes) {
                                    var types = dbTypes[category] || [];
                                    for (var t = 0; t < types.length; t++) {
                                        var candidate = (types[t].name || '').toLowerCase();
                                        if (candidate.indexOf('varchar') !== -1 || candidate.indexOf('char') !== -1 || candidate.indexOf('string') !== -1) {
                                            found = types[t].name; break;
                                        }
                                    }
                                    if (found) break;
                                }
                            }

                            if (found) {
                                column.type.name = found;
                            } else {
                                // If nothing matched, fallback to the first available platform type name so select isn't empty
                                try {
                                    for (var categoryFallback in dbTypes) {
                                        if (!Object.prototype.hasOwnProperty.call(dbTypes, categoryFallback)) continue;
                                        var typesFallback = dbTypes[categoryFallback] || [];
                                        if (Array.isArray(typesFallback) && typesFallback.length) {
                                            column.type.name = typesFallback[0].name;
                                            break;
                                        }
                                    }
                                } catch (e) {
                                    // ignore
                                }
                            }
                        }
                    } catch (e) {
                        // ignore normalization errors
                    }

                    // Normalize boolean flags: can be 'NO'/'YES' or '0'/'1' or boolean
                    if (column.notnull === 'NO') {
                        column.notnull = true;
                    } else if (column.notnull === 'YES') {
                        column.notnull = false;
                    } else {
                        column.notnull = !!column.notnull;
                    }

                    column.unsigned = !!column.unsigned;
                    column.autoincrement = !!column.autoincrement;

                    // Ensure default exists
                    if (!Object.prototype.hasOwnProperty.call(column, 'default')) {
                        column.default = null;
                    }

                    return column;
                }

                function normalizeTableObj(tbl) {
                    if (!tbl) return null;
                    // If columns is a keyed object, convert to array
                    if (tbl.columns && !Array.isArray(tbl.columns)) {
                        // Convert object's values to array
                        try {
                            tbl.columns = Object.values(tbl.columns);
                        } catch (e) {
                            tbl.columns = [];
                        }
                    }

                    if (Array.isArray(tbl.columns)) {
                        for (var i = 0; i < tbl.columns.length; i++) {
                            tbl.columns[i] = normalizeColumn(tbl.columns[i]);
                        }
                    }

                    // Ensure indexes is an array
                    if (!Array.isArray(tbl.indexes)) {
                        try { tbl.indexes = Object.values(tbl.indexes || {}); } catch (e) { tbl.indexes = []; }
                    }

                    return tbl;
                }

                // Parse/normalize originalTable and oldTable safely
                var orig = parseIfString(this.originalTable);
                var oldt = parseIfString(this.oldTable);

                orig = normalizeTableObj(orig);
                oldt = normalizeTableObj(oldt);

                // Debugging: show the raw and normalized objects in console
                console.log('voyager: originalTable (raw):', orig);
                console.log('voyager: oldTable (raw):', oldt);

                this.originalTable = orig;
                this.oldTable = oldt;

                // If old table is set, use it to repopulate the form
                if (this.oldTable) {
                    this.table = this.oldTable;
                } else {
                    // Deep clone to avoid mutating originalTable reference
                    this.table = JSON.parse(JSON.stringify(this.originalTable));
                }
            },
            methods: {
                stringifyTable() {
                    this.tableJson = JSON.stringify(this.table);

                    this.$nextTick(() => this.$refs.form.submit());
                }
            }
        });
    </script>

@stop
