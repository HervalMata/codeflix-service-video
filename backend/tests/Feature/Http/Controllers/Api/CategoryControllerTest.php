<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;
use Tests\Traits\TestResources;
use Tests\Traits\TestSaves;
use Tests\Traits\TestValidations;

class CategoryControllerTest extends TestCase
{
    use DatabaseMigrations;
    use TestValidations;
    use TestSaves;
    use TestResources;

    private $category;
    private $serializedFields = [
        'id',
        'name',
        'description',
        'is_active',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = factory(Category::class)->create();
    }

    public function testIndex()
    {
        $response = $this->get(route('categories.index'));

        $response
            ->assertStatus(200)
            ->assertJson([
                'meta' => ['per_page' => 15]
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => $this->serializedFields
                ],
                'links' => [],
                'meta' => [],
            ]);

        $resource = CategoryResource::collection(collect([$this->category]));
        $this->assertResource($response, $resource);
    }

    public function testShow()
    {
        $response = $this->get(route('categories.show', ['category' => $this->category->id]));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => $this->serializedFields
            ]);

        $id = $response->json('data.id');
        $resource = new CategoryResource(Category::find($id));
        $this->assertResource($response, $resource);
    }

    public function testInvalidationRulePost()
    {
        $this->assertInvalidationInStoreAction(['is_active' => 'a'], 'boolean');
        $this->assertInvalidationInStoreAction(['name' => ''], 'required');
        $this->assertInvalidationInStoreAction(
            ['name' => str_repeat('a', 256)],
            'max.string',
            ['max' => 255]
        );
    }

    public function testInvalidationRulePut()
    {
        $this->assertInvalidationInUpdateAction(['is_active' => 'a'], 'boolean');
        $this->assertInvalidationInUpdateAction(['name' => ''], 'required');
        $this->assertInvalidationInUpdateAction(
            ['name' => str_repeat('a', 256)],
            'max.string',
            ['max' => 255]
        );
    }

    public function testStore()
    {
        $response = $this->assertStore(
            [
                'name' => 'teste'
            ],
            [
                'name' => 'teste',
                'description' => null,
                'is_active' => true,
                'deleted_at' => null,
            ]
        );

        $response->assertJsonStructure([
            'data' => $this->serializedFields
        ]);

        $this->assertStore(
            [
                'name' => 'teste',
                'description' => 'description',
                'is_active' => false
            ],
            [
                'name' => 'teste',
                'description' => 'description',
                'is_active' => false,
                'deleted_at' => null,
            ]
        );

        $id = $response->json('data.id');
        $resource = new CategoryResource(Category::find($id));
        $this->assertResource($response, $resource);
    }

    public function testUpdate()
    {
        $this->category = factory(Category::class)->create([
            'description' => 'description',
            'is_active' => false,
        ]);

        $data = [
            'name' => 'teste',
            'description' => 'description',
            'is_active' => true
        ];

        $response = $this->assertUpdate($data,$data + ['deleted_at' => null]);

        $response->assertJsonStructure([
            'data' => $this->serializedFields
        ]);

        $id = $response->json('data.id');
        $resource = new CategoryResource(Category::find($id));
        $this->assertResource($response, $resource);

        $data = [
            'name' => 'test',
            'description' => '',
        ];
        $this->assertUpdate($data, array_merge($data, ['description' => null]));

        $data['description'] = 'test';
        $this->assertUpdate($data, array_merge($data, ['description' => 'test']));

        $data['description'] = null;
        $this->assertUpdate($data, array_merge($data, ['description' => null]));
    }

    public function testDestroy()
    {
        $category = factory(Category::class)->create();

        $response = $this->json('DELETE', route('categories.destroy', ['category' => $this->category->id]));

        $response
            ->assertStatus(204);

        $this->assertEmpty($response->getContent());

    }

    protected function routeStore()
    {
        return route('categories.store');
    }

    protected function routeUpdate()
    {
        return route('categories.update', ['category' => $this->category->id]);
    }

    protected function model()
    {
        return Category::class;
    }
}
