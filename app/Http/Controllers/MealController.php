<?php

namespace App\Http\Controllers;

use App\Models\Meal;
use App\Models\Category;
use App\Models\MealsSizeCost;
use App\Models\MealWithAddon;
use App\Models\Addon;
use App\Models\Extra;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;


class MealController extends Controller
{
    // Fetch all meal
    public function index(Request $request)
    {
        $meals = Meal::query();
    
        if (!Auth::guard('admin-api')->check()) {
            $meals->where('status', true);
        }
    
        $mealsData = $meals->with('mealSizeCosts')->get();
    
        $DataMeals = $mealsData->map(function ($meal) {
            return $this->DataMeal($meal);
        });
    
        return response()->json(['status' => 'success', 'data' => $DataMeals], 200);
    }

    // Fetch meal by ID
    public function show($id)
    {
        $meal = Meal::with('mealSizeCosts')->find($id);

        if (!$meal) {
            return response()->json(['status' => 'failed', 'error' => 'Meal not found'], 404);
        }

        if (!$this->canViewMeal($meal)) {
            return response()->json(['status' => 'failed', 'error' => 'Meal not found'], 404);
        }

        $dataMeal = $this->DataMeal($meal);
        return response()->json(['status' => 'success', 'data' => $dataMeal], 200);
    }

    // Fetch size and cost to meal by ID
    public function showSizeCost($mealId)
    {
        $meal = Meal::find($mealId);

        if (!$meal) {
            return response()->json(['status' => 'failed', 'error' => 'Meal not found'], 404);
        }

        $mealSizeCosts = $meal->mealSizeCosts;

        $DataMealSizeCosts = $mealSizeCosts->map(function ($sizeCost) {
            $costData = [
                'id' => $sizeCost->id,
                'meal_id' => $sizeCost->meal_id,
                'cost' => $sizeCost->cost,
            ];

            if (!is_null($sizeCost->number_of_pieces)) {
                $costData['number_of_pieces'] = $sizeCost->number_of_pieces;
            }

            if (!is_null($sizeCost->size)) {
                $costData['size'] = $sizeCost->size;
            }

            return $costData;
        });

        return response()->json(['status' => 'success', 'data' => $DataMealSizeCosts], 200);
    }

    // Add a new meal
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => ['required', 'string', 'regex:/^(?=(?:[\p{L}\s\'&]{0,}[\p{L}]){3,50}$)[\p{L}\s\'&]*$/u', 'unique:meals'],
            'description' => ['required', 'string', 'min:10', 'max:255', 'regex:/^\s*\S(?:.*\S)?\s*$/u'],
            'type' => 'required|in:vegetarian,non-vegetarian',
            'category_id' => 'required|exists:categories,id',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,bmp,svg',
            'status' => 'sometimes|boolean',
            'size' => ['required', 'integer', 'min:1', 'max:4'],
            'cost' => ['required', 'numeric', 'min:1'],
            'number_of_pieces' => ['nullable', 'integer', 'min:1'],
        ]);

        $imagePath = $this->handleImageUpload($request);

        DB::beginTransaction();

        try {
            $newMeal = Meal::create([
                'name' => $validatedData['name'],
                'description' => $validatedData['description'],
                'type' => $validatedData['type'],
                'category_id' => $validatedData['category_id'],
                'image' => $imagePath,
                'status' => $validatedData['status'] ?? true,
            ]);

            $newMeal->mealSizeCosts()->create([
                'size' => $validatedData['size'],
                'cost' => $validatedData['cost'],
                'number_of_pieces' => $validatedData['number_of_pieces'] ?? null,
            ]);

            DB::commit();

            return response()->json(['status' => 'success', 'message' => "The meal has been added successfully"], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // Update a meal
    public function update(Request $request, $id)
    {
        $meal = Meal::find($id);
    
        if (!$meal) {
            return response()->json(['status' => 'failed', 'error' => 'Meal not found'], 404);
        }
    
        $validatedData = $request->validate([
            'name' => ['sometimes', 'string', 'regex:/^(?=(?:[\p{L}\s\'&]{0,}[\p{L}]){3,50}$)[\p{L}\s\'&]*$/u', Rule::unique('meals')->ignore($id)],
            'description' => ['sometimes', 'string', 'min:10', 'max:255', 'regex:/^\s*\S(?:.*\S)?\s*$/u'],
            'type' => 'sometimes|in:vegetarian,non-vegetarian',
            'category_id' => 'sometimes|exists:categories,id',
            'image' => 'sometimes|image|mimes:jpeg,png,jpg,gif,bmp,svg',
            'status' => 'sometimes|boolean',
            'size' => ['sometimes', 'integer', 'min:1', 'max:4'],
            'cost' => ['sometimes', 'numeric', 'min:1'],
            'number_of_pieces' => ['nullable', 'integer', 'min:1'],
        ]);
    
        if ($request->hasFile('image')) {
            $this->deleteOldImage($meal);
            $validatedData['image'] = $this->handleImageUpload($request);
        }
    
        $meal->update([
            'name' => $validatedData['name'] ?? $meal->name,
            'description' => $validatedData['description'] ?? $meal->description,
            'type' => $validatedData['type'] ?? $meal->type,
            'category_id' => $validatedData['category_id'] ?? $meal->category_id,
            'image' => $validatedData['image'] ?? $meal->image,
            'status' => $validatedData['status'] ?? $meal->status,
        ]);
    
        if ($request->has('size') || $request->has('cost') || $request->has('number_of_pieces')) {
            $mealSizeCostData = [
                'size' => $validatedData['size'] ?? null,
                'cost' => $validatedData['cost'] ?? null,
                'number_of_pieces' => $validatedData['number_of_pieces'] ?? null,
            ];
    
            $existingSizeCost = $meal->mealSizeCosts()->where('size', $mealSizeCostData['size'])->first();
    
            if ($existingSizeCost) {
                $existingSizeCost->update($mealSizeCostData);
            } else {
                $meal->mealSizeCosts()->create($mealSizeCostData);
            }
        }
    
        return response()->json(['status' => 'success', 'message' => "The meal has been updated successfully"], 200);
    }
    

    //add  size
    public function updateMealSizeCost(Request $request, $mealId)
{
    $meal = Meal::find($mealId);
    if (!$meal) {
        return response()->json(['status' => 'failed', 'error' => 'Meal not found'], 404);
    }

    $validatedData = $request->validate([
        'id' => ['sometimes', 'integer', 'min:1'],
        'number_of_pieces' => ['nullable', 'integer', 'min:1'],
        'size' => ['required', 'integer', 'min:1', 'max:4'],
        'cost' => ['required', 'numeric', 'min:1'],
    ]);

    if (isset($validatedData['id'])) {
        $sizeCost = $meal->mealSizeCosts()->find($validatedData['id']);
        if ($sizeCost) {
            // تحقق مما إذا كان نفس الحجم موجود مع سجل آخر لنفس الوجبة
            $existingSizeCost = $meal->mealSizeCosts()
                ->where('size', $validatedData['size'])
                ->where('id', '!=', $validatedData['id'])
                ->first();

            if ($existingSizeCost) {
                return response()->json(['status' => 'failed', 'error' => 'Size already exists for this meal with another record'], 422);
            }

            // تحديث السجل إذا لم يكن هناك تعارض في الحجم
            $sizeCost->update([
                'size' => $validatedData['size'], // تحديث الحجم
                'number_of_pieces' => $validatedData['number_of_pieces'] ?? $sizeCost->number_of_pieces,
                'cost' => $validatedData['cost'],
            ]);

            return response()->json(['status' => 'success', 'message' => "The meal has been updated successfully"], 200);
        } else {
            return response()->json(['status' => 'failed', 'error' => 'Size cost not found'], 404);
        }
    } else {
        // التحقق مما إذا كان الحجم موجودًا بالفعل لنفس الوجبة
        $existingSizeCost = $meal->mealSizeCosts()
            ->where('size', $validatedData['size'])
            ->first();

        if ($existingSizeCost) {
            return response()->json(['status' => 'failed', 'error' => 'Size already exists for this meal'], 422);
        }

        // إنشاء سجل جديد
        $meal->mealSizeCosts()->create([
            'size' => $validatedData['size'],
            'number_of_pieces' => $validatedData['number_of_pieces'] ?? null,
            'cost' => $validatedData['cost'],
        ]);

        return response()->json(['status' => 'success', 'message' => "The meal has been updated successfully"], 200);
    }
}

    
    
    
    
    // Delete a meal
    public function deleteMeal($id)
    {
        $meal = Meal::with('mealSizeCosts')->find($id);
        if (!$meal) {
            return response()->json(['status' => 'failed', 'error' => 'Meal not found'], 404);
        }
        $this->deleteOldImage($meal);
        $meal->mealSizeCosts()->delete();
        $meal->delete();

        return response()->json(['status' => 'success', 'message' => 'Meal deleted successfully'], 200);
    }

    // Filter meals by category
    public function filterByCategory(Request $request, $categoryId)
    {
        $category = Category::find($categoryId);
        if (!$category) {
            return response()->json(['status' => 'failed', 'error' => 'Category not found'], 404);
        }

        $meals = Meal::where('category_id', $categoryId);

        if (!Auth::guard('admin-api')->check()) {
            $meals->where('status', true);
        }

        $perPage = 12;

        $meals = $meals->paginate($perPage);

        $DataMeals = $meals->map(function ($meal) {
            return $this->DataMeal($meal);
        });

        $pagination = [
            'total' => $meals->total(),
            'per_page' => $meals->perPage(),
            'current_page' => $meals->currentPage(),
            'last_page' => $meals->lastPage(),
        ];

        if ($meals->currentPage() > $meals->lastPage()) {
            return response()->json(['status' => 'failed', 'error' => 'Page number exceeds the last available page'], 400);
        }

        return $DataMeals->isEmpty()
            ? response()->json(['status' => 'failed', 'message' => 'No meals found for this category'], 404)
            : response()->json(['status' => 'success', 'data' => $DataMeals, "pagination" => $pagination], 200);
    }

    // Filter meals by type (vegetarian or non-vegetarian)
    public function filterByType(Request $request, $type)
    {

        if (!in_array($type, ['vegetarian', 'non-vegetarian'])) {
            return response()->json(['status' => 'failed', 'error' => 'Invalid type'], 400);
        }

        $perPage = 12;

        $mealsQuery = Meal::where('type', $type);

        if (!Auth::guard('admin-api')->check()) {
            $mealsQuery->where('status', true);
        }


        $meals = $mealsQuery->paginate($perPage);

        if ($meals->currentPage() > $meals->lastPage()) {
            return response()->json(['status' => 'failed', 'error' => 'Page number exceeds the last available page'], 400);
        }
        //  meals data
        $DataMeals = $meals->map(function ($meal) {
            return $this->DataMeal($meal);
        });

        $pagination = [
            'total' => $meals->total(),
            'per_page' => $meals->perPage(),
            'current_page' => $meals->currentPage(),
            'last_page' => $meals->lastPage(),
        ];
        return $DataMeals->isEmpty()
            ? response()->json(['status' => 'failed', 'message' => 'No meals found for this type'], 404)
            : response()->json(['status' => 'success', 'data' => $DataMeals, "pagination" => $pagination], 200);
    }

    // Filter meals by status (active or inactive)
    public function filterByStatus(Request $request, $status)
    {
        if (!in_array($status, ['active', 'inactive'])) {
            return response()->json(['status' => 'failed', 'error' => 'Invalid status value. Please enter active or inactive'], 400);
        }

        $perPage = 12;

        $mealsQuery = Meal::where('status', $status === 'active' ? true : false);

        if (!Auth::guard('admin-api')->check()) {
            $mealsQuery->where('status', true);
        }

        $meals = $mealsQuery->paginate($perPage);

        if ($meals->currentPage() > $meals->lastPage()) {
            return response()->json(['status' => 'failed', 'error' => 'Page number exceeds the last available page'], 400);
        }
        // Format meals data
        $DataMeals = $meals->map(function ($meal) {
            return $this->DataMeal($meal);
        });

        $pagination = [
            'total' => $meals->total(),
            'per_page' => $meals->perPage(),
            'current_page' => $meals->currentPage(),
            'last_page' => $meals->lastPage(),
        ];

        return $DataMeals->isEmpty()
            ? response()->json(['status' => 'failed', 'message' => 'No meals found for this status'], 404)
            : response()->json(['status' => 'success', 'data' => $DataMeals, "pagination" => $pagination], 200);
    }

    // function to  meal data
    private function DataMeal($meal)
    {
        $mealCosts = $meal->mealSizeCosts->map(function ($sizeCost) {
            return [
                'id' => $sizeCost->id,
                'cost' => $sizeCost->cost,
                'number_of_pieces' => $sizeCost->number_of_pieces ?? null,
                'size' => $sizeCost->size ?? null,
            ];
        });

        $minCostDetails = $mealCosts->isEmpty() ? null : $mealCosts->sortBy('cost')->first();

        $category = Category::where('id', $meal->category_id)->first();

        return [
            'id' => $meal->id,
            'name' => $meal->name,
            'description' => $meal->description,
            'image' => $meal->image,
            'type' => $meal->type,
            'status' => $meal->status,
            'category_name' => $category ? $category->name : null,
            'category_id' => $meal->category_id,
            'cost' => $minCostDetails['cost'] ?? null,
            'size' => $minCostDetails['size'] ?? null,
            'number_of_pieces' => $minCostDetails['number_of_pieces'] ?? null,
        ];
    }

    // function to handle image upload
    private function handleImageUpload($request)
    {
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = Str::random(40) . '.' . $image->getClientOriginalExtension();
            $imagePath = $image->storeAs('meals', $imageName, 'public');
            return $imagePath;
        }

        return null;
    }

    // Helper method to delete old image
    private function deleteOldImage($meal)
    {
        if ($meal->image) {
            Storage::disk('public')->delete($meal->image);
        }
    }

    // Helper method to check if meal can be viewed
    private function canViewMeal($meal)
    {
        return Auth::guard('admin-api')->check() || $meal->status;
    }

    // filter meals by ID
    public function filterMeal(Request $request)
    {
        $query = Meal::query();

        $filters = $request->only(['name', 'cost', 'size', 'number_of_pieces', 'category_id', 'status', 'type']);
        
        foreach ($filters as $key => $value) {
            if (!is_null($value)) {
                if ($key == 'name') {
                    $query->where($key, 'like', '%' . $value . '%');
                } elseif (in_array($key, ['cost', 'size', 'number_of_pieces'])) {
                    $query->whereHas('mealSizeCosts', function($q) use ($key, $value) {
                        $q->where($key, $value);
                    });
                } else {
                    $query->where($key, $value);
                }
            }
        }
        
        $meals = $query->with(['category', 'mealSizeCosts'])->get();
        
        if ($meals->isEmpty()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'No meals found with the given filters'
            ], 404);
        }

        $DataMeals = $meals->map(function ($meal) use ($filters) {
            // جلب التكاليف المطابقة للفلاتر إذا كانت موجودة
            $mealCosts = $meal->mealSizeCosts->filter(function ($sizeCost) use ($filters) {
                foreach (['cost', 'size', 'number_of_pieces'] as $filter) {
                    if (isset($filters[$filter]) && $sizeCost->$filter != $filters[$filter]) {
                        return false;
                    }
                }
                return true;
            });

            $cost = $mealCosts->isNotEmpty() ? $mealCosts->first()->cost : null;

            return [
                'id' => $meal->id,
                'name' => $meal->name,
                'description' => $meal->description,
                'cost' => $cost,
                'type' => $meal->type,
                'category_id' => $meal->category_id,
                'category_name' => optional($meal->category)->name,
                'status' => $meal->status,
                'image' => $meal->image,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $DataMeals,
        ], 200);
    }


}
