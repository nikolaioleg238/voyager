@section('database-types-template')

<div>
    <select :value="column.type.name" @change="onTypeChange" class="form-control">
        <optgroup v-for="(types, category) in dbTypes" :label="category">
            <option v-for="type in types" :value="type.name" :disabled="type.notSupported">
                @{{ type.name.toUpperCase() }}
            </option>
        </optgroup>
    </select>
    <div v-if="column.type.notSupported">
        <small>{{ __('voyager::database.type_not_supported') }}</small>
    </div>
</div>


@endsection

<script>
    let databaseTypes = {!! json_encode($db->types) !!};

    // Build a name -> type lookup map (lowercased) for fast, reliable access.
    const databaseTypesMap = {};
    try {
        for (let category in databaseTypes) {
            if (!Object.prototype.hasOwnProperty.call(databaseTypes, category)) continue;
            const types = databaseTypes[category] || [];

            if (!Array.isArray(types)) continue;
            types.forEach(function(t) {
                if (t && t.name) {
                    databaseTypesMap[(t.name || '').toLowerCase()] = t;
                }
            });
        }
    } catch (e) {
        // ignore malformed databaseTypes
    }

    function getDbType(name) {
        let type;
        name = (name || '').toLowerCase().trim();

        // Fast lookup in the map
        if (name && databaseTypesMap[name]) {
            return databaseTypesMap[name];
        }

        // Helper synonyms to try when a direct match isn't found
        const synonyms = {
            'timestamp': ['timestamp', 'datetime', 'timestamptz'],
            'datetime': ['datetime', 'timestamp', 'timestamptz'],
            'integer': ['integer', 'int', 'bigint', 'smallint', 'serial'],
            'int': ['int', 'integer', 'bigint', 'smallint', 'serial']
        };

        // Helper: try direct lookup in map or across categories for a given name
        function tryLookup(nm) {
            if (!nm) return null;
            nm = (nm || '').toLowerCase().trim();
            if (databaseTypesMap[nm]) return databaseTypesMap[nm];

            for (let category in databaseTypes) {
                if (!Object.prototype.hasOwnProperty.call(databaseTypes, category)) continue;
                const types = databaseTypes[category] || [];
                if (!Array.isArray(types)) continue;
                const found = types.find(function (t) {
                    return nm === (t.name || '').toLowerCase();
                });
                if (found) return found;
            }
            return null;
        }

        // 1) Try synonyms first (map or scan)
        if (synonyms[name]) {
            for (let i = 0; i < synonyms[name].length; i++) {
                const alt = synonyms[name][i];
                const res = tryLookup(alt);
                if (res) return res;
            }
        }

        // 2) Try exact lookup across categories
        const exact = tryLookup(name);
        if (exact) return exact;

        // 3) Try substring heuristics when exact names differ across DB drivers
        function findBySubstrings(subs) {
            subs = Array.isArray(subs) ? subs : [subs];
            for (let i = 0; i < subs.length; i++) {
                const sub = subs[i];
                for (let category in databaseTypes) {
                    if (!Object.prototype.hasOwnProperty.call(databaseTypes, category)) continue;
                    const types = databaseTypes[category] || [];
                    if (!Array.isArray(types)) continue;
                    const found = types.find(function (t) {
                        return (t && t.name && (t.name || '').toLowerCase().indexOf(sub) !== -1);
                    });
                    if (found) return found;
                }
            }
            return null;
        }

        // Integer-like: match 'int', 'serial', 'bigint', etc.
        if (name && (name.indexOf('int') !== -1 || name === 'integer')) {
            const found = findBySubstrings(['int', 'serial', 'bigint', 'smallint']);
            if (found) return found;
        }

        // Timestamp/datetime-like: match 'timestamp', 'datetime', 'time', 'date'
        if (name && (name.indexOf('time') !== -1 || name.indexOf('date') !== -1 || name === 'timestamp' || name === 'datetime')) {
            const found = findBySubstrings(['timestamp', 'datetime', 'timestamptz']);
            if (found) return found;
        }

        // Generic fallbacks: numeric-like and common text types
        const numericLike = findBySubstrings(['int', 'decimal', 'numeric', 'float', 'double']);
        if (numericLike) return numericLike;
        const commonLike = findBySubstrings(['varchar', 'text', 'string', 'char']);
        if (commonLike) return commonLike;

        // Nothing matched — show error and pick a safe fallback
        toastr.error("{{ __('voyager::database.unknown_type') }}: " + name);

        // Fallback to the first available type in any category
        for (let category in databaseTypes) {
            if (!Object.prototype.hasOwnProperty.call(databaseTypes, category)) {
                continue;
            }
            const types = databaseTypes[category];
            if (Array.isArray(types) && types.length > 0) {
                return types[0];
            }
        }

        // Final safe fallback if databaseTypes is empty or malformed
        return { name: 'integer', notSupported: false };
    }

    Vue.component('database-types', {
        props: {
            column: {
                type: Object,
                required: true
            }
        },
        data() {
            return {
                dbTypes: databaseTypes
            };
        },
        template: `@yield('database-types-template')`,
        methods: {
            onTypeChange(event) {
                this.$emit('typeChanged', this.getType(event.target.value));
            },
            getType(name) {
                return getDbType(name);
            }
        }
    });
</script>
