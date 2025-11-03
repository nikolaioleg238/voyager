@section('database-table-helper-buttons-template')
    <div>
        <div class="btn btn-success" @click="addNewColumn">+ {{ __('voyager::database.add_new_column') }}</div>
        <div class="btn btn-success" @click="addTimestamps">+ {{ __('voyager::database.add_timestamps') }}</div>
        <div class="btn btn-success" @click="addSoftDeletes">+ {{ __('voyager::database.add_softdeletes') }}</div>
    </div>
@endsection

<script>
    Vue.component('database-table-helper-buttons', {
        template: `@yield('database-table-helper-buttons-template')`,
        methods: {
            addColumn(column) {
                this.$emit('columnAdded', column);
            },
            makeColumn(options) {
                return $.extend({
                    name: '',
                    oldName: '',
                    // Use safe fallback type objects instead of calling getDbType() here.
                    // Calling getDbType() can produce toastr errors if the server-provided
                    // db types are missing or not yet available in the browser.
                    type: { name: 'integer', notSupported: false },
                    length: null,
                    fixed: false,
                    unsigned: false,
                    autoincrement: false,
                    notnull: false,
                    default: null
                }, options);
            },
            addNewColumn() {
                this.addColumn(this.makeColumn());
            },
            addTimestamps() {
                this.addColumn(this.makeColumn({
                    name: 'created_at',
                    // safe fallback for datetime
                    type: { name: 'datetime', notSupported: false }
                }));

                this.addColumn(this.makeColumn({
                    name: 'updated_at',
                    type: { name: 'datetime', notSupported: false }
                }));
            },
            addSoftDeletes() {
                this.addColumn(this.makeColumn({
                    name: 'deleted_at',
                    type: { name: 'datetime', notSupported: false }
                }));
            }
        }
    });
</script>
