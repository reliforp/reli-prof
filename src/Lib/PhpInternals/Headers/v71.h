/*
   +----------------------------------------------------------------------+
   | Zend Engine                                                          |
   +----------------------------------------------------------------------+
   | Copyright (c) Zend Technologies Ltd. (http://www.zend.com)           |
   +----------------------------------------------------------------------+
   | This source file is subject to version 2.00 of the Zend license,     |
   | that is bundled with this package in the file LICENSE, and is        |
   | available through the world-wide-web at the following url:           |
   | http://www.zend.com/license/2_00.txt.                                |
   | If you did not receive a copy of the Zend license and are unable to  |
   | obtain it through the world-wide-web, please send a note to          |
   | license@zend.com so we can mail you a copy immediately.              |
   +----------------------------------------------------------------------+
   | The contents of this file are extracted from some headers in php-src |
   +----------------------------------------------------------------------+
*/

// zend_long.h
typedef int64_t zend_long;
typedef uint64_t zend_ulong;
typedef int64_t zend_off_t;

// zend_types.h
typedef unsigned char zend_bool;
typedef unsigned char zend_uchar;
typedef intptr_t zend_intptr_t;
typedef uintptr_t zend_uintptr_t;

typedef struct _zend_object_handlers zend_object_handlers;
typedef struct _zend_class_entry     zend_class_entry;
typedef union  _zend_function        zend_function;
typedef struct _zend_execute_data    zend_execute_data;

typedef struct _zval_struct     zval;

typedef struct _zend_refcounted zend_refcounted;
typedef struct _zend_string     zend_string;
typedef struct _zend_array      zend_array;
typedef struct _zend_object     zend_object;
typedef struct _zend_resource   zend_resource;
typedef struct _zend_reference  zend_reference;
typedef struct _zend_ast_ref    zend_ast_ref;
typedef struct _zend_ast        zend_ast;

typedef int  (*compare_func_t)(const void *, const void *);
typedef void (*swap_func_t)(void *, void *);
typedef void (*sort_func_t)(void *, size_t, size_t, compare_func_t, swap_func_t);
typedef void (*dtor_func_t)(zval *pDest);
typedef void (*copy_ctor_func_t)(zval *pElement);

typedef struct zend_refcounted_h {
	uint32_t refcount;
	union {
		struct {
			struct {
				zend_uchar type;
				zend_uchar flags;    /* used for strings & objects */
				uint16_t gc_info;    /* keeps GC root number (or 0) and color */
            }
		} v;
		uint32_t type_info;
	} u;
} zend_refcounted_h;

struct _zend_refcounted {
	zend_refcounted_h gc;
};

struct _zend_string {
	zend_refcounted_h gc;
	zend_ulong        h;                /* hash value */
	size_t            len;
	char              val[1];
};

typedef union _zend_value {
	zend_long         lval;				/* long value */
	double            dval;				/* double value */
	zend_refcounted  *counted;
	zend_string      *str;
	zend_array       *arr;
	zend_object      *obj;
	zend_resource    *res;
	zend_reference   *ref;
	zend_ast_ref     *ast;
	zval             *zv;
	void             *ptr;
	zend_class_entry *ce;
	zend_function    *func;
	struct {
		uint32_t w1;
		uint32_t w2;
	} ww;
} zend_value;

struct _zval_struct {
	zend_value        value;			/* value */
	union {
		struct {
				zend_uchar    type;			/* active type */
				zend_uchar    type_flags;
				zend_uchar    const_flags;
				zend_uchar    reserved;		/* call info for EX(This) */
		} v;
		uint32_t type_info;
	} u1;
	union {
		uint32_t     next;                 /* hash collision chain */
		uint32_t     cache_slot;           /* cache slot (for RECV_INIT) */
		uint32_t     lineno;               /* line number (for ast nodes) */
		uint32_t     num_args;             /* arguments number for EX(This) */
		uint32_t     fe_pos;               /* foreach position */
		uint32_t     fe_iter_idx;          /* foreach iterator index */
		uint32_t     access_flags;         /* class constant access flags */
		uint32_t     property_guard;       /* single property guard */
		uint32_t     extra;                /* not further specified */
	} u2;
};

typedef struct _Bucket {
	zval              val;
	zend_ulong        h;                /* hash value (or numeric index)   */
	zend_string      *key;              /* string key or NULL for numerics */
} Bucket;

struct _zend_array {
	zend_refcounted_h gc;
	union {
		struct {
				zend_uchar    flags;
				zend_uchar    nApplyCount;
				zend_uchar    nIteratorsCount;
				zend_uchar    consistency;
		} v;
		uint32_t flags;
	} u;
	uint32_t          nTableMask;
	Bucket           *arData;
	uint32_t          nNumUsed;
	uint32_t          nNumOfElements;
	uint32_t          nTableSize;
	uint32_t          nInternalPointer;
	zend_long         nNextFreeElement;
	dtor_func_t       pDestructor;
};

typedef struct _zend_array HashTable;
typedef uint32_t HashPosition;

typedef struct _HashTableIterator {
	HashTable    *ht;
	HashPosition  pos;
} HashTableIterator;

struct _zend_object {
	zend_refcounted_h gc;
	uint32_t          handle; // TODO: may be removed ???
	zend_class_entry *ce;
	const zend_object_handlers *handlers;
	HashTable        *properties;
	zval              properties_table[1];
};

struct _zend_resource {
	zend_refcounted_h gc;
	int               handle; // TODO: may be removed ???
	int               type;
	void             *ptr;
};

// zend_compile.h
typedef struct _zend_property_info {
	uint32_t offset; /* property offset for object properties or
	                      property index for static properties */
	uint32_t flags;
	zend_string *name;
	zend_string *doc_comment;
	zend_class_entry *ce;
} zend_property_info;

// zend_types.h
struct _zend_reference {
	zend_refcounted_h              gc;
	zval                           val;
};

struct _zend_ast_ref {
	zend_refcounted_h gc;
	zend_ast         *ast;
};

// zend_object_handlers.h
struct _zend_object_handlers {
	/* offset of real object header (usually zero) */
	int  offset;
	/* general object functions */
	void *free_obj;
	void *dtor_obj;
	void *clone_obj;
	/* individual object functions */
	void *read_property;
	void *write_property;
	void *read_dimension;
	void *write_dimension;
	void *get_property_ptr_ptr;
	void *get;
	void *set;
	void *has_property;
	void *unset_property;
	void *has_dimension;
	void *unset_dimension;
	void *get_properties;
	void *get_method;
	void *call_method;
	void *get_constructor;
	void *get_class_name;
	void *compare_objects;
	void *cast_object;
	void *count_elements;
	void *get_debug_info;
	void *get_closure;
	void *get_gc;
	void *do_operation;
	void *compare;
};

// zend_globals.h
typedef struct _zend_vm_stack *zend_vm_stack;

// zend_execute.h
struct _zend_vm_stack {
	zval *top;
	zval *end;
	zend_vm_stack prev;
};

// zend_globals_macros.h
typedef struct _zend_compiler_globals zend_compiler_globals;
typedef struct _zend_executor_globals zend_executor_globals;
typedef struct _zend_php_scanner_globals zend_php_scanner_globals;
typedef struct _zend_ini_scanner_globals zend_ini_scanner_globals;

// zend_globals.h
typedef struct _zend_ini_entry zend_ini_entry;

// zend_ini.h
struct _zend_ini_entry {
	zend_string *name;
	int (*on_modify)(zend_ini_entry *entry, zend_string *new_value, void *mh_arg1, void *mh_arg2, void *mh_arg3, int stage);
	void *mh_arg1;
	void *mh_arg2;
	void *mh_arg3;
	zend_string *value;
	zend_string *orig_value;
	void (*displayer)(zend_ini_entry *ini_entry, int type);
	int modifiable;

	int orig_modifiable;
	int modified;
	int module_number;
};

// zend_iterators.h
typedef struct _zend_object_iterator zend_object_iterator;

typedef struct _zend_object_iterator_funcs {
	/* release all resources associated with this iterator instance */
	void (*dtor)(zend_object_iterator *iter);

	/* check for end of iteration (FAILURE or SUCCESS if data is valid) */
	int (*valid)(zend_object_iterator *iter);

	/* fetch the item data for the current element */
	zval *(*get_current_data)(zend_object_iterator *iter);

	/* fetch the key for the current element (optional, may be NULL). The key
	 * should be written into the provided zval* using the ZVAL_* macros. If
	 * this handler is not provided auto-incrementing integer keys will be
	 * used. */
	void (*get_current_key)(zend_object_iterator *iter, zval *key);

	/* step forwards to next element */
	void (*move_forward)(zend_object_iterator *iter);

	/* rewind to start of data (optional, may be NULL) */
	void (*rewind)(zend_object_iterator *iter);

	/* invalidate current value/key (optional, may be NULL) */
	void (*invalidate_current)(zend_object_iterator *iter);
} zend_object_iterator_funcs;

struct _zend_object_iterator {
	zend_object std;
	zval data;
	const zend_object_iterator_funcs *funcs;
	zend_ulong index; /* private to fe_reset/fe_fetch opcodes */
};

typedef struct _zend_class_iterator_funcs {
	zend_object_iterator_funcs  *funcs;
    zend_function *zf_new_iterator;
	zend_function *zf_valid;
	zend_function *zf_current;
	zend_function *zf_key;
	zend_function *zf_next;
	zend_function *zf_rewind;
} zend_class_iterator_funcs;

// zend.h
typedef enum {
	EH_NORMAL = 0,
	EH_SUPPRESS,
	EH_THROW
} zend_error_handling_t;

typedef struct {
	zend_error_handling_t  handling;
	zend_class_entry       *exception;
	zval                   user_handler;
} zend_error_handling;

// zend_objects_API.h
typedef struct _zend_objects_store {
	zend_object **object_buckets;
	uint32_t top;
	uint32_t size;
	int free_list_head;
} zend_objects_store;

// zend_modules.h
typedef struct _zend_module_entry zend_module_entry;
typedef struct _zend_module_dep zend_module_dep;
struct _zend_module_dep {
	const char *name;		/* module name */
	const char *rel;		/* version relationship: NULL (exists), lt|le|eq|ge|gt (to given version) */
	const char *version;	/* version */
	unsigned char type;		/* dependency type */
};

// zend_compile.h
typedef void (*zif_handler)(zend_execute_data *execute_data, zval *return_value);

// zend_API.h
typedef struct _zend_function_entry {
	const char *fname;
	zif_handler handler;
	const struct _zend_internal_arg_info *arg_info;
	uint32_t num_args;
	uint32_t flags;
} zend_function_entry;

typedef struct _zend_fcall_info_cache {
	zend_bool initialized;
	zend_function *function_handler;
	zend_class_entry *calling_scope;
	zend_class_entry *called_scope;
	zend_object *object;
} zend_fcall_info_cache;

// zend_modules.h
struct _zend_module_entry {
	unsigned short size;
	unsigned int zend_api;
	unsigned char zend_debug;
	unsigned char zts;
	const struct _zend_ini_entry *ini_entry;
	const struct _zend_module_dep *deps;
	const char *name;
	const struct _zend_function_entry *functions;
	int (*module_startup_func)(int type, int module_number);
	int (*module_shutdown_func)(int type, int module_number);
	int (*request_startup_func)(int type, int module_number);
	int (*request_shutdown_func)(int type, int module_number);
	void (*info_func)(zend_module_entry *zend_module);
	const char *version;
	size_t globals_size;
	void* globals_ptr; // ts_rsrc_id* globals_id_ptr; in ZTS
	void (*globals_ctor)(void *global);
	void (*globals_dtor)(void *global);
	int (*post_deactivate_func)(void);
	int module_started;
	unsigned char type;
	void *handle;
	int module_number;
	const char *build_id;
};

// zend_stack.h
typedef struct _zend_stack {
	int size, top, max;
	void *elements;
} zend_stack;

// zend_compile.h
typedef struct _zend_op zend_op;
typedef struct _zend_op_array zend_op_array;

typedef union _znode_op {
	uint32_t      constant;
	uint32_t      var;
	uint32_t      num;
	uint32_t      opline_num; /*  Needs to be signed */
	uint32_t      jmp_offset;
} znode_op;

struct _zend_op {
	const void *handler;
	znode_op op1;
	znode_op op2;
	znode_op result;
	uint32_t extended_value;
	uint32_t lineno;
	zend_uchar opcode;
	zend_uchar op1_type;
	zend_uchar op2_type;
	zend_uchar result_type;
};

typedef struct _zend_arg_info {
	zend_string *name;
	zend_string *class_name;
	zend_uchar type_hint;
	zend_uchar pass_by_reference;
	zend_bool allow_null;
	zend_bool is_variadic;
} zend_arg_info;

typedef struct _zend_internal_arg_info {
	const char *name;
	const char *class_name;
	zend_uchar type_hint;
	zend_uchar pass_by_reference;
	zend_bool allow_null;
	zend_bool is_variadic;
} zend_internal_arg_info;

typedef struct _zend_live_range {
	uint32_t var; /* low bits are used for variable type (ZEND_LIVE_* macros) */
	uint32_t start;
	uint32_t end;
} zend_live_range;

typedef struct _zend_try_catch_element {
	uint32_t try_op;
	uint32_t catch_op;  /* ketchup! */
	uint32_t finally_op;
	uint32_t finally_end;
} zend_try_catch_element;

struct _zend_op_array {
	/* Common elements */
	zend_uchar type;
	zend_uchar arg_flags[3]; /* bitset of arg_info.pass_by_reference */
	uint32_t fn_flags;
	zend_string *function_name;
	zend_class_entry *scope;
	zend_function *prototype;
	uint32_t num_args;
	uint32_t required_num_args;
	zend_arg_info *arg_info;
	/* END of common elements */

	uint32_t *refcount;

	uint32_t last;
	zend_op *opcodes;

	int last_var;
	uint32_t T;
	zend_string **vars;

	int last_live_range;
	int last_try_catch;
	zend_live_range *live_range;
	zend_try_catch_element *try_catch_array;

	/* static variables support */
	HashTable *static_variables;

	zend_string *filename;
	uint32_t line_start;
	uint32_t line_end;
	zend_string *doc_comment;
	uint32_t early_binding; /* the linked list of delayed declarations */

	int last_literal;
	zval *literals;

	int  cache_size;
	void **run_time_cache;

	void *reserved[6];
};

typedef struct _zend_internal_function_info {
	zend_uintptr_t required_num_args;
	const char *class_name;
	zend_uchar type_hint;
	zend_bool return_reference;
	zend_bool allow_null;
	zend_bool _is_variadic;
} zend_internal_function_info;


typedef struct _zend_internal_function {
	/* Common elements */
	zend_uchar type;
	zend_uchar arg_flags[3]; /* bitset of arg_info.pass_by_reference */
	uint32_t fn_flags;
	zend_string* function_name;
	zend_class_entry *scope;
	zend_function *prototype;
	uint32_t num_args;
	uint32_t required_num_args;
	zend_internal_arg_info *arg_info;
	/* END of common elements */

	zif_handler handler;
	struct _zend_module_entry *module;
	void *reserved[6];
} zend_internal_function;

union _zend_function {
	zend_uchar type;	/* MUST be the first element of this struct! */
	uint32_t   quick_arg_flags;

	struct {
		zend_uchar type;  /* never used */
		zend_uchar arg_flags[3]; /* bitset of arg_info.pass_by_reference */
		uint32_t fn_flags;
		zend_string *function_name;
		zend_class_entry *scope;
		zend_function *prototype;
		uint32_t num_args;
		uint32_t required_num_args;
		zend_arg_info *arg_info;  /* index -1 represents the return value info, if any */
	} common;

	zend_op_array op_array;
	zend_internal_function internal_function;
};

// zend_compile.h
typedef struct _zend_class_constant {
	zval value; /* access flags are stored in reserved: zval.u2.access_flags */
	zend_string *doc_comment;
	zend_class_entry *ce;
} zend_class_constant;

struct _zend_execute_data {
	const zend_op       *opline;           /* executed opline                */
	zend_execute_data   *call;             /* current call                   */
	zval                *return_value;
	zend_function       *func;             /* executed function              */
	zval                 This;             /* this + call_info + num_args    */
	zend_execute_data   *prev_execute_data;
	zend_array          *symbol_table;
	void               **run_time_cache;   /* cache op_array->run_time_cache */
	zval                *literals;         /* cache op_array->literals   */
};

typedef struct _zend_brk_cont_element {
	int start;
	int cont;
	int brk;
	int parent;
} zend_brk_cont_element;

typedef struct _zend_oparray_context {
	uint32_t   opcodes_size;
	int        vars_size;
	int        literals_size;
	int        backpatch_count;
	int        in_finally;
	uint32_t   fast_call_var;
	uint32_t   try_catch_offset;
	int        current_brk_cont;
	int        last_brk_cont;
	zend_brk_cont_element *brk_cont_array;
	HashTable *labels;
} zend_oparray_context;

typedef struct _zend_declarables {
	zend_long ticks;
} zend_declarables;

typedef struct _znode { /* used only during compilation */
	zend_uchar op_type;
	zend_uchar flag;
	union {
		znode_op op;
		zval constant; /* replaced by literal/zv */
	} u;
} znode;

typedef struct _zend_file_context {
	zend_declarables declarables;
	znode implementing_class;

	zend_string *current_namespace;
	zend_bool in_namespace;
	zend_bool has_bracketed_namespaces;

	HashTable *imports;
	HashTable *imports_function;
	HashTable *imports_const;
} zend_file_context;

/** zend_llist.h */

typedef void (*llist_dtor_func_t)(void *);
typedef struct _zend_llist_element {
	struct _zend_llist_element *next;
	struct _zend_llist_element *prev;
	char data[1]; /* Needs to always be last in the struct */
} zend_llist_element;

typedef struct _zend_llist {
	zend_llist_element *head;
	zend_llist_element *tail;
	size_t count;
	size_t size;
	llist_dtor_func_t dtor;
	unsigned char persistent;
	zend_llist_element *traverse_ptr;
} zend_llist;

// zend_arena.h
typedef struct _zend_arena zend_arena;

struct _zend_arena {
	char		*ptr;
	char		*end;
	zend_arena  *prev;
};

// zend_multibyte.h
typedef struct _zend_encoding zend_encoding;

// zend_constants.h
typedef struct _zend_constant {
	zval value;
	zend_string *name;
	int flags;
	int module_number;
} zend_constant;

// zend_globals.h
struct _zend_compiler_globals {
	zend_stack loop_var_stack;

	zend_class_entry *active_class_entry;

	zend_string *compiled_filename;

	int zend_lineno;

	zend_op_array *active_op_array;

	HashTable *function_table;	/* function symbol table */
	HashTable *class_table;		/* class table */

	HashTable filenames_table;

	HashTable *auto_globals;

	zend_bool parse_error;
	zend_bool in_compilation;
	zend_bool short_tags;

	zend_bool unclean_shutdown;

	zend_bool ini_parser_unbuffered_errors;

	zend_llist open_files;

	struct _zend_ini_parser_param *ini_parser_param;

	uint32_t start_lineno;
	zend_bool increment_lineno;

	zend_string *doc_comment;
	uint32_t extra_fn_flags;

	uint32_t compiler_options; /* set of ZEND_COMPILE_* constants */

	HashTable const_filenames;

	zend_oparray_context context;
	zend_file_context file_context;

	zend_arena *arena;

	zend_string *empty_string;
	zend_string *one_char_string[256];
	zend_string **known_strings;
	uint32_t    known_strings_count;

	HashTable interned_strings;

	const zend_encoding **script_encoding_list;
	size_t script_encoding_list_size;
	zend_bool multibyte;
	zend_bool detect_unicode;
	zend_bool encoding_declared;

	zend_ast *ast;
	zend_arena *ast_arena;

	zend_stack delayed_oplines_stack;
};

struct _zend_compiler_globals_zts {
    struct _zend_compiler_globals cg;
	zval **static_members_table;
	int last_static_member;
};

struct _zend_executor_globals {
	zval uninitialized_zval;
	zval error_zval;

	/* symbol table cache */
	zend_array *symtable_cache[32];
	/* Pointer to one past the end of the symtable_cache */
	zend_array **symtable_cache_limit;
	/* Pointer to first unused symtable_cache slot */
	zend_array **symtable_cache_ptr;

	zend_array symbol_table;		/* main symbol table */

	HashTable included_files;	/* files already included */

	void *bailout;

	int error_reporting;
	int exit_status;

	HashTable *function_table;	/* function symbol table */
	HashTable *class_table;		/* class table */
	HashTable *zend_constants;	/* constants table */

	zval          *vm_stack_top;
	zval          *vm_stack_end;
	zend_vm_stack  vm_stack;

	struct _zend_execute_data *current_execute_data;
	zend_class_entry *fake_scope; /* used to avoid checks accessing properties */

	zend_long precision;

	int ticks_count;

	HashTable *in_autoload;
	zend_function *autoload_func;
	zend_bool full_tables_cleanup;

	/* for extended information support */
	zend_bool no_extensions;

	zend_bool vm_interrupt;
	zend_bool timed_out;
	zend_long hard_timeout;

	HashTable regular_list;
	HashTable persistent_list;

	int user_error_handler_error_reporting;
	zval user_error_handler;
	zval user_exception_handler;
	zend_stack user_error_handlers_error_reporting;
	zend_stack user_error_handlers;
	zend_stack user_exception_handlers;

	zend_error_handling_t  error_handling;
	zend_class_entry      *exception_class;

	/* timeout support */
	zend_long timeout_seconds;

	int lambda_count;

	HashTable *ini_directives;
	HashTable *modified_ini_directives;
	zend_ini_entry *error_reporting_ini_entry;

	zend_objects_store objects_store;
	zend_object *exception;
	zend_object *prev_exception;
	const zend_op *opline_before_exception;
	zend_op exception_op[3];

	struct _zend_module_entry *current_module;

	zend_bool active;
	zend_uchar flags;

	zend_long assertions;

	uint32_t           ht_iterators_count;     /* number of allocatd slots */
	uint32_t           ht_iterators_used;      /* number of used slots */
	HashTableIterator *ht_iterators;
	HashTableIterator  ht_iterators_slots[16];

	void *saved_fpu_cw_ptr;

	zend_function trampoline;
	zend_op       call_trampoline_op;

	void *reserved[6];
};

// zend.h
typedef struct _zend_trait_method_reference {
	zend_string *method_name;
	zend_class_entry *ce;
	zend_string *class_name;
} zend_trait_method_reference;

typedef struct _zend_trait_precedence {
	zend_trait_method_reference *trait_method;
	union {
		zend_class_entry  *ce;
		zend_string       *class_name;
	} *exclude_from_classes;
} zend_trait_precedence;

typedef struct _zend_trait_alias {
	zend_trait_method_reference trait_method;

	/**
	* name for method to be added
	*/
	zend_string *alias;

	/**
	* modifiers to be set on trait method
	*/
	uint32_t modifiers;
} zend_trait_alias;

struct _zend_serialize_data;
struct _zend_unserialize_data;

typedef struct _zend_serialize_data zend_serialize_data;
typedef struct _zend_unserialize_data zend_unserialize_data;

struct _zend_class_entry {
	char type;
	zend_string *name;
	struct _zend_class_entry *parent;
	int refcount;
	uint32_t ce_flags;

	int default_properties_count;
	int default_static_members_count;
	zval *default_properties_table;
	zval *default_static_members_table;
	zval *static_members_table__ptr;
	HashTable function_table;
	HashTable properties_info;
	HashTable constants_table;

	zend_function *constructor;
	zend_function *destructor;
	zend_function *clone;
	zend_function *__get;
	zend_function *__set;
	zend_function *__unset;
	zend_function *__isset;
	zend_function *__call;
	zend_function *__callstatic;
	zend_function *__tostring;
	zend_function *__debugInfo;
	zend_function *serialize_func;
	zend_function *unserialize_func;

	/* allocated only if class implements Iterator or IteratorAggregate interface */
	zend_class_iterator_funcs *iterator_funcs_ptr;

	/* handlers */
	zend_object* (*create_object)(zend_class_entry *class_type);
	zend_object_iterator *(*get_iterator)(zend_class_entry *ce, zval *object, int by_ref);
	int (*interface_gets_implemented)(zend_class_entry *iface, zend_class_entry *class_type); /* a class implements this interface */
	union _zend_function *(*get_static_method)(zend_class_entry *ce, zend_string* method);

	/* serializer callbacks */
	int (*serialize)(zval *object, unsigned char **buffer, size_t *buf_len, zend_serialize_data *data);
	int (*unserialize)(zval *object, zend_class_entry *ce, const unsigned char *buf, size_t buf_len, zend_unserialize_data *data);

	uint32_t num_interfaces;
	uint32_t num_traits;
	zend_class_entry **interfaces;

	zend_class_entry **traits;
	zend_trait_alias **trait_aliases;
	zend_trait_precedence **trait_precedences;

	union {
		struct {
			zend_string *filename;
			uint32_t line_start;
			uint32_t line_end;
			zend_string *doc_comment;
		} user;
		struct {
			const struct _zend_function_entry *builtin_functions;
			struct _zend_module_entry *module;
		} internal;
	} info;
};

/** libc */
typedef unsigned int mode_t;
typedef unsigned long int dev_t;
typedef unsigned long int ino_t;
typedef long int off_t;
typedef long int nlink_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
typedef int pid_t;
typedef long int blksize_t;
typedef long int blkcnt_t;
typedef unsigned long int fsblkcnt64_t;
typedef unsigned long int uint64_t;

struct timespec
{
    long tv_sec;
    long tv_nsec;
};

struct stat
{
    dev_t st_dev;
    ino_t st_ino;
    nlink_t st_nlink;
    mode_t st_mode;
    uid_t st_uid;
    gid_t st_gid;
    int __pad0;
    dev_t st_rdev;
    off_t st_size;
    blksize_t st_blksize;
    blkcnt_t st_blocks;
    struct timespec st_atim;
    struct timespec st_mtim;
    struct timespec st_ctim;
    long int reserved[3];
};

/** zend_stream.h */
typedef struct stat zend_stat_t;

/** zend_alloc.h */
typedef struct _zend_mm_heap zend_mm_heap;

typedef struct _zend_mm_storage zend_mm_storage;
typedef	void* (*zend_mm_chunk_alloc_t)(zend_mm_storage *storage, size_t size, size_t alignment);
typedef void  (*zend_mm_chunk_free_t)(zend_mm_storage *storage, void *chunk, size_t size);
typedef bool   (*zend_mm_chunk_truncate_t)(zend_mm_storage *storage, void *chunk, size_t old_size, size_t new_size);
typedef bool   (*zend_mm_chunk_extend_t)(zend_mm_storage *storage, void *chunk, size_t old_size, size_t new_size);
typedef struct _zend_mm_handlers {
	zend_mm_chunk_alloc_t       chunk_alloc;
	zend_mm_chunk_free_t        chunk_free;
	zend_mm_chunk_truncate_t    chunk_truncate;
	zend_mm_chunk_extend_t      chunk_extend;
} zend_mm_handlers;
struct _zend_mm_storage {
	const zend_mm_handlers handlers;
	void *data;
};

/** zend_alloc.c */
typedef uint32_t   zend_mm_page_info; /* 4-byte integer */
typedef zend_ulong zend_mm_bitset;    /* 4-byte or 8-byte integer */

typedef zend_mm_bitset zend_mm_page_map[(((size_t) (2 * 1024 * 1024)) / (4 * 1024)) / (sizeof(zend_mm_bitset) * 8)];     /* 64B */

typedef struct  _zend_mm_page      zend_mm_page;
typedef struct  _zend_mm_bin       zend_mm_bin;
typedef struct  _zend_mm_free_slot zend_mm_free_slot;
typedef struct  _zend_mm_chunk     zend_mm_chunk;
typedef struct  _zend_mm_huge_list zend_mm_huge_list;

struct _zend_mm_heap {
	int                use_custom_heap;
	zend_mm_storage   *storage;
	size_t             size;                    /* current memory usage */
	size_t             peak;                    /* peak memory usage */
	zend_mm_free_slot *free_slot[30]; /* free lists for small sizes */
	size_t             real_size;               /* current size of allocated pages */
	size_t             real_peak;               /* peak size of allocated pages */
	size_t             limit;                   /* memory limit */
	int                overflow;                /* memory overflow flag */

	zend_mm_huge_list *huge_list;               /* list of huge allocated blocks */

	zend_mm_chunk     *main_chunk;
	zend_mm_chunk     *cached_chunks;			/* list of unused chunks */
	int                chunks_count;			/* number of alocated chunks */
	int                peak_chunks_count;		/* peak number of allocated chunks for current request */
	int                cached_chunks_count;		/* number of cached chunks */
	double             avg_chunks_count;		/* average number of chunks allocated per request */
	int                last_chunks_delete_boundary; /* numer of chunks after last deletion */
	int                last_chunks_delete_count;    /* number of deletion over the last boundary */
	union {
		struct {
			void      *(*_malloc)(size_t);
			void       (*_free)(void*);
			void      *(*_realloc)(void*, size_t);
		} std;
	} custom_heap;
};

struct _zend_mm_chunk {
	zend_mm_heap      *heap;
	zend_mm_chunk     *next;
	zend_mm_chunk     *prev;
	uint32_t           free_pages;				/* number of free pages */
	uint32_t           free_tail;               /* number of free pages at the end of chunk */
	uint32_t           num;
	char               reserve[64 - (sizeof(void*) * 3 + sizeof(uint32_t) * 3)];
	zend_mm_heap       heap_slot;               /* used only in main chunk */
	zend_mm_page_map   free_map;                /* 512 bits or 64 bytes */
	zend_mm_page_info  map[((size_t) (2 * 1024 * 1024)) / (4 * 1024)];      /* 2 KB = 512 * 4 */
};

struct _zend_mm_page {
	char               bytes[(4 * 1024)];
};

struct _zend_mm_bin {
	char               bytes[(4 * 1024) * 8];
};

struct _zend_mm_free_slot {
	zend_mm_free_slot *next_free_slot;
};

struct _zend_mm_huge_list {
	intptr_t           ptr;
	size_t             size;
	zend_mm_huge_list *next;
};

/* zend_closures.c */
typedef struct _zend_closure {
	zend_object       std;
	zend_function     func;
	zval              this_ptr;
	zend_class_entry *called_scope;
	zif_handler       orig_internal_handler;
} zend_closure;

// main/SAPI.h
/*
   +----------------------------------------------------------------------+
   | Copyright (c) The PHP Group                                          |
   +----------------------------------------------------------------------+
   | This source file is subject to version 3.01 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available through the world-wide-web at the following url:           |
   | http://www.php.net/license/3_01.txt                                  |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Author:  Zeev Suraski <zeev@php.net>                                 |
   +----------------------------------------------------------------------+
*/

typedef struct {
	char *header;
	size_t header_len;
} sapi_header_struct;

typedef struct {
	zend_llist headers;
	int http_response_code;
	unsigned char send_default_content_type;
	char *mimetype;
	char *http_status_line;
} sapi_headers_struct;

typedef struct _sapi_post_entry sapi_post_entry;
typedef struct _sapi_module_struct sapi_module_struct;

typedef struct {
	const char *request_method;
	char *query_string;
	char *cookie_data;
	zend_long content_length;

	char *path_translated;
	char *request_uri;

	/* Do not use request_body directly, but the php://input stream wrapper instead */
	struct _php_stream *request_body;

	const char *content_type;

	zend_bool headers_only;
	zend_bool no_headers;
	zend_bool headers_read;

	sapi_post_entry *post_entry;

	char *content_type_dup;

	/* for HTTP authentication */
	char *auth_user;
	char *auth_password;
	char *auth_digest;

	/* this is necessary for the CGI SAPI module */
	char *argv0;

	char *current_user;
	int current_user_length;

	/* this is necessary for CLI module */
	int argc;
	char **argv;
	int proto_num;
} sapi_request_info;

typedef struct _sapi_globals_struct {
	void *server_context;
	sapi_request_info request_info;
	sapi_headers_struct sapi_headers;
	int64_t read_post_bytes;
	unsigned char post_read;
	unsigned char headers_sent;
	zend_stat_t global_stat;
	char *default_mimetype;
	char *default_charset;
	HashTable *rfc1867_uploaded_files;
	zend_long post_max_size;
	int options;
	zend_bool sapi_started;
	double global_request_time;
	HashTable known_post_content_types;
	zval callback_func;
	zend_fcall_info_cache fci_cache;
} sapi_globals_struct;
