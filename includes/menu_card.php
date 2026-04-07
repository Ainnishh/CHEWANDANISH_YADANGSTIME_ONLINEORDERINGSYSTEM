<?php
?>

<div class="menu-card <?php echo $item['is_featured'] ? 'featured' : ''; ?>" 
     data-item-id="<?php echo $item['id']; ?>"
     data-category="<?php echo htmlspecialchars($item['category']); ?>">
    
    <!-- Featured Badge -->
    <?php if ($item['is_featured']): ?>
        <div class="featured-badge" style="position: absolute; top: 15px; right: -30px; background: var(--accent-color); color: var(--dark-text); padding: 0.3rem 3rem; font-size: 0.8rem; font-weight: bold; transform: rotate(45deg); z-index: 1;">
            <i class="fas fa-star"></i> Featured
        </div>
    <?php endif; ?>
    
    <!-- Item Image -->
    <img src="<?php echo htmlspecialchars($item['image_url'] ?? 'images/default-drink.jpg'); ?>" 
         alt="<?php echo htmlspecialchars($item['name']); ?>" 
         class="card-image"
         style="width: 100%; height: 200px; object-fit: cover; transition: transform 0.8s ease;"
         onerror="this.src='images/default-drink.jpg'">
    
    <!-- Card Content -->
    <div class="card-content" style="padding: 1.5rem;">
        <!-- Header -->
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
            <h3 class="item-name" style="font-size: 1.4rem; font-weight: 700; color: var(--text-light); line-height: 1.3;">
                <?php echo htmlspecialchars($item['name']); ?>
            </h3>
            <div class="item-price" 
                 data-base-price="<?php echo $item['price']; ?>"
                 style="background: rgba(250, 161, 143, 0.2); color: var(--accent-color); padding: 0.5rem 1rem; border-radius: 20px; font-weight: bold; font-size: 1.3rem; border: 1px solid var(--accent-color);">
                RM <?php echo number_format($item['price'], 2); ?>
            </div>
        </div>
        
        <!-- Description -->
        <p class="item-description" style="color: rgba(232, 223, 208, 0.8); font-size: 0.95rem; line-height: 1.6; margin-bottom: 1rem;">
            <?php echo htmlspecialchars($item['description'] ?? 'Delicious beverage from Yadang\'s Time'); ?>
        </p>
        
        <!-- Details -->
        <div class="item-details" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <?php if (!empty($item['calories'])): ?>
                <span class="detail-item" style="display: flex; align-items: center; gap: 5px; color: rgba(232, 223, 208, 0.7); font-size: 0.9rem;">
                    <i class="fas fa-fire" style="color: var(--accent-color);"></i>
                    <span><?php echo $item['calories']; ?> cal</span>
                </span>
            <?php endif; ?>
            
            <?php if (!empty($item['prep_time'])): ?>
                <span class="detail-item" style="display: flex; align-items: center; gap: 5px; color: rgba(232, 223, 208, 0.7); font-size: 0.9rem;">
                    <i class="fas fa-clock" style="color: var(--accent-color);"></i>
                    <span><?php echo $item['prep_time']; ?> min</span>
                </span>
            <?php endif; ?>
            
            <?php if (!empty($item['ingredients'])): ?>
                <span class="detail-item" style="display: flex; align-items: center; gap: 5px; color: rgba(232, 223, 208, 0.7); font-size: 0.9rem;">
                    <i class="fas fa-leaf" style="color: var(--accent-color);"></i>
                    <span>Fresh ingredients</span>
                </span>
            <?php endif; ?>
        </div>
        
        <!-- Customization Options -->
        <div class="customization-options" style="background: rgba(26, 44, 34, 0.5); padding: 1rem; border-radius: 10px; margin-bottom: 1rem; border: 1px solid rgba(250, 161, 143, 0.1);">
            <!-- Size Option -->
            <div class="option-group" style="margin-bottom: 0.8rem;">
                <label class="option-label" style="display: block; color: var(--text-light); font-size: 0.9rem; margin-bottom: 0.5rem; font-weight: 500;">
                    Size
                </label>
                <select class="option-select" name="size" style="width: 100%; padding: 0.5rem; background: rgba(45, 70, 56, 0.8); border: 1px solid rgba(250, 161, 143, 0.3); border-radius: 5px; color: var(--text-light); font-size: 0.9rem;">
                    <option value="regular" data-additional-price="0">Regular</option>
                    <option value="large" data-additional-price="1.50">Large (+RM 1.50)</option>
                </select>
            </div>
            
            <!-- Sweetness Option -->
            <div class="option-group" style="margin-bottom: 0.8rem;">
                <label class="option-label" style="display: block; color: var(--text-light); font-size: 0.9rem; margin-bottom: 0.5rem; font-weight: 500;">
                    Sweetness
                </label>
                <select class="option-select" name="sweetness" style="width: 100%; padding: 0.5rem; background: rgba(45, 70, 56, 0.8); border: 1px solid rgba(250, 161, 143, 0.3); border-radius: 5px; color: var(--text-light); font-size: 0.9rem;">
                    <option value="100">100% Sugar</option>
                    <option value="75">75% Sugar</option>
                    <option value="50">50% Sugar</option>
                    <option value="25">25% Sugar</option>
                    <option value="0">No Sugar</option>
                </select>
            </div>
            
            <!-- Temperature Option (for hot/cold drinks) -->
            <?php if (in_array($item['category'], ['Coffee', 'Tea'])): ?>
                <div class="option-group" style="margin-bottom: 0.8rem;">
                    <label class="option-label" style="display: block; color: var(--text-light); font-size: 0.9rem; margin-bottom: 0.5rem; font-weight: 500;">
                        Temperature
                    </label>
                    <select class="option-select" name="temperature" style="width: 100%; padding: 0.5rem; background: rgba(45, 70, 56, 0.8); border: 1px solid rgba(250, 161, 143, 0.3); border-radius: 5px; color: var(--text-light); font-size: 0.9rem;">
                        <option value="hot">Hot</option>
                        <option value="cold">Cold</option>
                        <option value="extra_ice">Extra Ice</option>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Actions -->
        <div class="card-actions" style="display: flex; gap: 1rem; margin-top: 1rem;">
            <!-- Quantity Control -->
            <div class="quantity-control" style="display: flex; align-items: center; background: rgba(26, 44, 34, 0.7); border-radius: 25px; border: 1px solid rgba(250, 161, 143, 0.3); overflow: hidden;">
                <button class="qty-btn qty-decrease" style="background: transparent; border: none; color: var(--text-light); width: 40px; height: 40px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; transition: all 0.3s ease;">
                    -
                </button>
                <span class="qty-display" style="width: 40px; text-align: center; font-weight: bold; color: var(--text-light);">
                    1
                </span>
                <button class="qty-btn qty-increase" style="background: transparent; border: none; color: var(--text-light); width: 40px; height: 40px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; transition: all 0.3s ease;">
                    +
                </button>
            </div>
            
            <!-- Add to Cart -->
            <button class="add-to-cart-btn" 
                    onclick="addToCart(<?php echo $item['id']; ?>, this)"
                    style="flex: 1; background: linear-gradient(135deg, var(--accent-color), var(--light-accent)); color: var(--dark-text); border: none; padding: 0 1.5rem; border-radius: 25px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 1rem;">
                <i class="fas fa-cart-plus"></i>
                Add to Cart
            </button>
            
            <!-- Favorite -->
            <button class="favorite-btn" 
                    onclick="toggleFavorite(<?php echo $item['id']; ?>, this)"
                    title="Add to favorites"
                    style="background: rgba(250, 161, 143, 0.1); border: 2px solid rgba(250, 161, 143, 0.3); color: var(--text-light); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s ease;">
                <i class="far fa-heart"></i>
            </button>
        </div>
    </div>
</div>
