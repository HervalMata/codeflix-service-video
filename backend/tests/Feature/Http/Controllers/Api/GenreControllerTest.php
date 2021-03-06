<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Http\Controllers\Api\GenreController;
use App\Http\Resources\GenreResource;
use App\Models\Category;
use App\Models\Genre;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Tests\Exceptions\TestException;
use Tests\TestCase;
use Tests\Traits\TestResources;
use Tests\Traits\TestSaves;
use Tests\Traits\TestValidations;

class GenreControllerTest extends TestCase
{
    use DatabaseMigrations;
    use TestValidations;
    use TestSaves;
    use TestResources;

    private $genre;
    private $sendData;
    private $serializedFields = [
        'id',
        'name',
        'is_active',
        'created_at',
        'updated_at',
        'deleted_at',
        'categories' => [
            '*' => [
                'id',
                'name',
                'description',
                'is_active',
                'created_at',
                'updated_at',
                'deleted_at'
            ]
        ]
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->genre = factory(Genre::class)->create();

        $this->sendData = [
            'name' => 'name',
            'is_active' => true
        ];
    }

    public function testIndex()
    {
        $response = $this->get(route('genres.index'));

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

        $resource = GenreResource::collection(collect([$this->genre]));
        $this->assertResource($response, $resource);
    }

    public function testShow()
    {
        $response = $this->get(route('genres.show', ['genre' => $this->genre->id]));

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => $this->serializedFields
            ]);

        $id = $response->json('data.id');
        $resource = new GenreResource(Genre::find($id));
        $this->assertResource($response, $resource);
    }

    public function testInvalidationRequiredRule()
    {
        $data = [
            'name' => '',
        ];

        $this->assertInvalidationInStoreAction($data,'required');
        $this->assertInvalidationInUpdateAction($data,'required');
    }

    public function testInvalidationBooleanRule()
    {
        $data = [
            'is_active' => 'as',
        ];

        $this->assertInvalidationInStoreAction($data,'boolean');
        $this->assertInvalidationInUpdateAction($data,'boolean');
    }

    public function testInvalidationMaxRule()
    {
        $data = [
            'name' => str_repeat('a', 256)
        ];

        $this->assertInvalidationInStoreAction($data,'max.string', ['max' => 255]);
        $this->assertInvalidationInUpdateAction($data,'max.string', ['max' => 255]);
    }

    public function testInvalidationArrayRule()
    {
        $data = [
            'categories_id' => 'a',
        ];

        $this->assertInvalidationInStoreAction($data,'array');
        $this->assertInvalidationInUpdateAction($data,'array');
    }

    public function testInvalidationExistsRule()
    {
        $data = [
            'categories_id' => [100],
        ];

        $this->assertInvalidationInStoreAction($data,'exists');
        $this->assertInvalidationInUpdateAction($data,'exists');
    }

    public function testInvalidationExistsDeletedRule()
    {
        $category = factory(Category::class)->create();
        $category->delete();

        $data = [
            'categories_id' => [$category->id],
        ];

        $this->assertInvalidationInStoreAction($data,'exists');
        $this->assertInvalidationInUpdateAction($data,'exists');
    }

    public function testSave()
    {
        $category = factory(Category::class)->create();

        $relations = [
            'categories_id' => [$category->id],
        ];

        $data = [
            [
                'send_data' => $this->sendData + $relations,
                'test_data' => $this->sendData + ['is_active' => true]
            ],
            [
                'send_data' => $this->sendData + ['is_active' => false] + $relations ,
                'test_data' => $this->sendData + ['is_active' => false]
            ],
        ];

        foreach ($data as $key => $value){
            $response = $this->assertStore(
                $value['send_data'],
                $value['test_data'] + ['deleted_at' => null]
            );

            $response->assertJsonStructure([
                'data' => $this->serializedFields
            ]);

            $id = $response->json('data.id');
            $resource = new GenreResource(Genre::find($id));
            $this->assertResource($response, $resource);

            $this->assertIfHasCategory(
                $response->json('data.id'),
                $value['send_data']['categories_id'][0]
            );

            $response = $this->assertUpdate(
                $value['send_data'],
                $value['test_data'] + ['deleted_at' => null]
            );

            $response->assertJsonStructure([
                'data' => $this->serializedFields
            ]);

            $this->assertIfHasCategory(
                $response->json('data.id'),
                $value['send_data']['categories_id'][0]
            );

            $id = $response->json('data.id');
            $resource = new GenreResource(Genre::find($id));
            $this->assertResource($response, $resource);
        }
    }

    public function testSyncCategories()
    {
        $categoriesId = factory(Category::class, 3)
            ->create()
            ->pluck('id')
            ->toArray();

        $sendData = [
            'name' => 'test',
            'categories_id' => [$categoriesId[0]]
        ];

        $response = $this->json('POST', $this->routeStore(), $sendData);
        $this->assertDatabaseHas('category_genre', [
            'category_id' => $categoriesId[0],
            'genre_id' => $response->json('data.id')
        ]);


        $sendData = [
            'name' => 'test',
            'categories_id' => [$categoriesId[1], $categoriesId[2]]
        ];

        $response = $this->json(
            'PUT',
            route('genres.update', ['genre' => $response->json('data.id')]),
            $sendData
        );
        $this->assertDatabaseMissing('category_genre', [
            'category_id' => $categoriesId[0],
            'genre_id' => $response->json('data.id')
        ]);
        $this->assertDatabaseHas('category_genre', [
            'category_id' => $categoriesId[1],
            'genre_id' => $response->json('data.id')
        ]);
        $this->assertDatabaseHas('category_genre', [
            'category_id' => $categoriesId[2],
            'genre_id' => $response->json('data.id')
        ]);
    }

    public function testRollBackStore()
    {
        $controller = \Mockery::mock(GenreController::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $controller->shouldReceive('validate')
            ->withAnyArgs()
            ->andReturn($this->sendData);

        $controller->shouldReceive('rulesStore')
            ->withAnyArgs()
            ->andReturn([]);

        $request = \Mockery::mock(Request::class);
        $request->shouldReceive('get')
            ->withAnyArgs()
            ->andReturn([]);

        $controller->shouldReceive('handleRelations')
            ->once()
            ->andThrow(new TestException());

        $hasError = false;
        try {
            $controller->store($request);
        } catch (TestException $exception) {
            $this->assertCount(1, Genre::all());
            $hasError = true;
        }

        $this->assertTrue($hasError);
    }

    public function testRollBackUpdate()
    {
        $controller = \Mockery::mock(GenreController::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $controller->shouldReceive('validate')
            ->withAnyArgs()
            ->andReturn($this->sendData);

        $controller->shouldReceive('rulesUpdate')
            ->withAnyArgs()
            ->andReturn([]);

        $controller->shouldReceive('findOrFail')
            ->withAnyArgs()
            ->andReturn($this->genre);

        $request = \Mockery::mock(Request::class);
        $request->shouldReceive('get')
            ->withAnyArgs()
            ->andReturn([]);

        $controller->shouldReceive('handleRelations')
            ->once()
            ->andThrow(new TestException());

        $hasError = false;
        try {
            $controller->update($request, 1);
        } catch (TestException $exception) {
            $this->assertCount(1, Genre::all());
            $hasError = true;
        }
        $this->assertTrue($hasError);
    }

    public function testDestroy()
    {
        $response = $this->json('DELETE', route('genres.destroy', ['genre' => $this->genre->id]));

        $response
            ->assertStatus(204);

        $this->assertEmpty($response->getContent());
    }

    protected function assertIfHasCategory($genreId, $categoryId)
    {
        $this->assertDatabaseHas('category_genre',
            [
                'category_id' => $categoryId,
                'genre_id' => $genreId
            ]
        );
    }

    protected function routeStore()
    {
        return route('genres.store');
    }

    protected function routeUpdate()
    {
        return route('genres.update', ['genre' => $this->genre->id]);
    }

    protected function model()
    {
        return Genre::class;
    }
}
