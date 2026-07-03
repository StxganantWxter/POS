<?php

namespace App\Crud;

use App\Models\Brand;
use App\Models\User;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\UsersService;
use Illuminate\Http\Request;
use TorMorten\Eventy\Facades\Events as Hook;

class BrandCrud extends CrudService
{
    /**
     * Define the autoload status
     */
    const AUTOLOAD = true;

    /**
     * Define the identifier
     */
    const IDENTIFIER = 'ns.products-brands';

    /**
     * define the base table
     */
    protected $table = 'nexopos_brands';

    /**
     * base route name
     */
    protected $mainRoute = 'ns.products-brands';

    /**
     * Define namespace
     *
     * @param  string
     */
    protected $namespace = 'ns.products-brands';

    /**
     * Model Used
     */
    protected $model = Brand::class;

    /**
     * Adding relation
     */
    public $relations = [
        'join' => [
            'user' => [ User::class, 'author' ],
        ],
    ];

    protected $pick = [
        'user' => [ 'username' ],
    ];

    protected $permissions = [
        'create' => 'nexopos.create.brands',
        'read' => 'nexopos.read.brands',
        'update' => 'nexopos.update.brands',
        'delete' => 'nexopos.delete.brands',
    ];

    /**
     * Define where statement
     *
     * @var array
     **/
    protected $listWhere = [];

    /**
     * Define where in statement
     *
     * @var array
     */
    protected $whereIn = [];

    /**
     * Fields which will be filled during post/put
     */
    public $fillable = [
        'name',
        'description',
        'author_id',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Return the label used for the crud
     * instance
     *
     * @return array
     **/
    public function getLabels()
    {
        return [
            'list_title' => __( 'Brands List' ),
            'list_description' => __( 'Display all the brands.' ),
            'no_entry' => __( 'No brand has been registered' ),
            'create_new' => __( 'Add a new brand' ),
            'create_title' => __( 'Create a new brand' ),
            'create_description' => __( 'Register a new brand and save it.' ),
            'edit_title' => __( 'Edit brand' ),
            'edit_description' => __( 'Modify an existing brand.' ),
            'back_to_list' => __( 'Return to Brands' ),
        ];
    }

    /**
     * Check whether a feature is enabled
     **/
    public function isEnabled( $feature ): bool
    {
        return false; // by default
    }

    /**
     * Fields
     *
     * @param  object/null
     * @return array of field
     */
    public function getForm( $entry = null )
    {
        return [
            'main' => [
                'label' => __( 'Name' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __( 'Provide a name to the brand.' ),
                'validation' => 'required',
            ],
            'tabs' => [
                'general' => [
                    'label' => __( 'General' ),
                    'fields' => [
                        [
                            'type' => 'ckeditor',
                            'name' => 'description',
                            'label' => __( 'Description' ),
                            'value' => $entry->description ?? '',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Filter POST input fields
     *
     * @param  array of fields
     */
    public function filterPostInputs( $inputs )
    {
        return $inputs;
    }

    /**
     * Filter PUT input fields
     *
     * @param  array of fields
     */
    public function filterPutInputs( $inputs, Brand $entry )
    {
        return $inputs;
    }

    /**
     * get
     *
     * @param  string
     * @return mixed
     */
    public function get( $param )
    {
        switch ( $param ) {
            case 'model': return $this->model;
                break;
        }
    }

    /**
     * Define Columns
     */
    public function getColumns(): array
    {
        return [
            'name' => [
                'label' => __( 'Name' ),
                '$direction' => '',
                '$sort' => true,
            ],
            'user_username' => [
                'label' => __( 'Author' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'created_at' => [
                'label' => __( 'Created At' ),
                '$direction' => '',
                '$sort' => true,
            ],
        ];
    }

    /**
     * Define actions
     */
    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $entry->action(
            identifier: 'edit',
            label: __( 'Edit' ),
            type: 'GOTO',
            url: ns()->url( '/dashboard/' . 'products/brands' . '/edit/' . $entry->id ),
        );

        $entry->action(
            identifier: 'delete',
            label: __( 'Delete' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/ns.products-brands/' . $entry->id ),
            confirm: [
                'message' => __( 'Would you like to delete this ?' ),
            ],
        );

        return $entry;
    }

    /**
     * Bulk Delete Action
     *
     * @param    object Request with object
     * @return  false/array
     */
    public function bulkAction( Request $request )
    {
        $user = app()->make( UsersService::class );

        if ( ! $user->is( [ 'admin', 'supervisor' ] ) ) {
            return response()->json( [
                'status' => 'error',
                'message' => __( 'You\'re not allowed to do this operation' ),
            ], 403 );
        }

        if ( $request->input( 'action' ) == 'delete_selected' ) {
            $status = [
                'success' => 0,
                'error' => 0,
            ];

            foreach ( $request->input( 'entries' ) as $id ) {
                $entity = $this->model::find( $id );
                if ( $entity instanceof Brand ) {
                    $entity->delete();
                    $status[ 'success' ]++;
                } else {
                    $status[ 'error' ]++;
                }
            }

            return $status;
        }

        return Hook::filter( $this->namespace . '-catch-action', false, $request );
    }

    /**
     * get Links
     *
     * @return array of links
     */
    public function getLinks(): array
    {
        return [
            'list' => ns()->url( 'dashboard/' . 'products/brands' ),
            'create' => ns()->url( 'dashboard/' . 'products/brands/create' ),
            'edit' => ns()->url( 'dashboard/' . 'products/brands/edit/' ),
            'post' => ns()->url( 'api/crud/' . 'ns.products-brands' ),
            'put' => ns()->url( 'api/crud/' . 'ns.products-brands/{id}' . '' ),
        ];
    }

    /**
     * Get Bulk actions
     *
     * @return array of actions
     **/
    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __( 'Delete Selected Brands' ),
                'identifier' => 'delete_selected',
                'url' => ns()->route( 'ns.api.crud-bulk-actions', [
                    'namespace' => $this->namespace,
                ] ),
            ],
        ] );
    }

    /**
     * get exports
     *
     * @return array of export formats
     **/
    public function getExports()
    {
        return [];
    }
}
