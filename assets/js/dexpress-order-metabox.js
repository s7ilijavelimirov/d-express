/**
 * D Express Order Metabox JavaScript
 * Complete version with Modern Workflow
 */

(function ($, window, document) {
    'use strict';

    // Main metabox controller
    const DExpressMetabox = {
        // Configuration from PHP
        config: window.dexpressMetabox || {},

        // Current state
        state: {
            orderId: null,
            orderItems: [],
            locations: [],
            currentStep: 'weights',
            isProcessing: false,
            editMode: false
        },

        // DOM elements cache
        elements: {},

        /**
         * Initialize the metabox
         */
        init: function () {
            this.cacheElements();
            this.bindEvents();
            this.initializeState();

            console.log('[DExpress] Metabox initialized', {
                orderId: this.state.orderId,
                config: this.config,
                elements: Object.keys(this.elements)
            });
        },

        /**
         * Cache frequently used DOM elements
         */
        cacheElements: function () {
            this.elements = {
                metabox: $('.dexpress-order-metabox'),
                weightsTable: $('.dexpress-weights-table'),
                createSection: $('#dexpress-create-section'),
                splitSection: $('#dexpress-split-section'),
                responseDiv: $('#dexpress-response')
            };

            // Get order ID from metabox
            this.state.orderId = this.elements.metabox.data('order-id');

            console.log('[DExpress] Elements cached', {
                metaboxFound: this.elements.metabox.length,
                orderId: this.state.orderId
            });
        },

        /**
         * Bind all event listeners - FIXED VERSION
         */
        bindEvents: function () {
            // Events će biti vezani od strane individualnih modula
            // Ova metoda je sada placeholder
            console.log('[DExpress] Main bindEvents called - modules will bind their own events');
        },

        /**
         * Initialize component state
         */
        initializeState: function () {
            // Get data from PHP via localized script
            if (window.dexpressOrderData) {
                this.state.orderItems = window.dexpressOrderData.orderItems || [];
                this.state.locations = window.dexpressOrderData.locations || [];

                console.log('[DExpress] State initialized from dexpressOrderData', {
                    orderItems: this.state.orderItems.length,
                    locations: this.state.locations.length
                });
            } else {
                console.warn('[DExpress] dexpressOrderData not found - state will be empty');
            }
        }
    };

    // Modern Workflow Manager
    const ModernWorkflow = {
        currentStep: 1,
        selectedPackageType: null, // 'single' or 'multiple'

        /**
         * Initialize modern workflow
         */
        init: function() {
            console.log('[DExpress] ModernWorkflow initializing');
            this.bindEvents();
            this.populateInitialContent();
        },

        /**
         * Bind workflow events
         */
        bindEvents: function() {
            $(document)
                // Package type selection
                .on('click', '#dexpress-select-single', this.selectSingle.bind(this))
                .on('click', '#dexpress-select-multiple', this.selectMultiple.bind(this))
                
                // Navigation
                .on('click', '#dexpress-back-to-selection', this.backToSelection.bind(this))
                
                // Create shipment
                .on('click', '#dexpress-create-shipment', this.createShipment.bind(this))
                
                // Auto-populate content when single mode
                .on('change', 'select[name="dexpress_sender_location_id"]', this.updateSingleContent.bind(this));
        },

        /**
         * Select single package option
         */
        selectSingle: function(e) {
            e.preventDefault();
            console.log('[DExpress] Selected single package');
            
            this.selectedPackageType = 'single';
            this.updatePackageSelection();
            this.goToStep(2);
            this.showSingleConfig();
        },

        /**
         * Select multiple packages option
         */
        selectMultiple: function(e) {
            e.preventDefault();
            console.log('[DExpress] Selected multiple packages');
            
            this.selectedPackageType = 'multiple';
            this.updatePackageSelection();
            this.goToStep(2);
            this.showMultipleConfig();
        },

        /**
         * Update visual selection of package type
         */
        updatePackageSelection: function() {
            $('.dexpress-package-option').removeClass('selected');
            
            if (this.selectedPackageType === 'single') {
                $('#dexpress-select-single').addClass('selected');
            } else if (this.selectedPackageType === 'multiple') {
                $('#dexpress-select-multiple').addClass('selected');
            }
        },

        /**
         * Go to specific step
         */
        goToStep: function(stepNumber) {
            console.log(`[DExpress] Moving to step ${stepNumber}`);
            
            // Hide all steps
            $('.dexpress-step').removeClass('dexpress-step-active').hide();
            
            // Show target step with animation
            setTimeout(() => {
                $(`#dexpress-step-${this.getStepName(stepNumber)}`).show().addClass('dexpress-step-active');
            }, 150);
            
            this.currentStep = stepNumber;
            this.updateStepHeader();
        },

        /**
         * Get step name by number
         */
        getStepName: function(stepNumber) {
            switch (stepNumber) {
                case 1: return 'selection';
                case 2: return 'config';
                default: return 'selection';
            }
        },

        /**
         * Update step header based on selection
         */
        updateStepHeader: function() {
            if (this.currentStep === 2) {
                const title = this.selectedPackageType === 'single' 
                    ? 'Konfiguracija jednog paketa'
                    : 'Konfiguracija više paketa';
                    
                $('#dexpress-config-title').text(title);
                
                const buttonText = this.selectedPackageType === 'single'
                    ? 'Kreiraj paket'
                    : 'Kreiraj sve pakete';
                    
                $('#dexpress-create-text').text(buttonText);
            }
        },

        /**
         * Back to package selection
         */
        backToSelection: function(e) {
            e.preventDefault();
            console.log('[DExpress] Going back to selection');
            
            this.selectedPackageType = null;
            this.updatePackageSelection();
            this.goToStep(1);
            this.hideBothConfigs();
        },

        /**
         * Show single package configuration
         */
        showSingleConfig: function() {
            $('#dexpress-single-config').show();
            $('#dexpress-multiple-config').hide();
            
            // Populate content immediately
            this.updateSingleContent();
        },

        /**
         * Show multiple packages configuration
         */
        showMultipleConfig: function() {
            $('#dexpress-single-config').hide();
            $('#dexpress-multiple-config').show();
        },

        /**
         * Hide both configurations
         */
        hideBothConfigs: function() {
            $('#dexpress-single-config').hide();
            $('#dexpress-multiple-config').hide();
        },

        /**
         * Create shipment based on selected type
         */
        createShipment: function(e) {
            e.preventDefault();
            console.log('[DExpress] Creating shipment, type:', this.selectedPackageType);
            
            if (DExpressMetabox.state.isProcessing) {
                console.log('[DExpress] Already processing');
                return;
            }

            if (this.selectedPackageType === 'single') {
                this.createSingleShipment();
            } else if (this.selectedPackageType === 'multiple') {
                this.createMultipleShipments();
            } else {
                console.error('[DExpress] No package type selected');
                alert('Molimo odaberite tip paketa');
            }
        },

        /**
         * Create single shipment
         */
        createSingleShipment: function() {
            const formData = {
                order_id: DExpressMetabox.state.orderId,
                sender_location_id: $('select[name="dexpress_sender_location_id"]').val(),
                content: $('#dexpress_content').val(),
                return_doc: $('input[name="dexpress_return_doc"]:checked').length > 0 ? 1 : 0,
                dispenser_id: $('input[name="dexpress_dispenser_id"]').val()
            };

            if (!this.validateShipmentData(formData)) {
                return;
            }

            this.sendShipmentRequest('dexpress_create_shipment', formData);
        },

        /**
         * Create multiple shipments
         */
        createMultipleShipments: function() {
            const splits = SplitPackageManager.collectSplitData();
            
            if (splits.length === 0) {
                alert('Morate definisati barem jedan paket!');
                return;
            }

            const data = {
                order_id: DExpressMetabox.state.orderId,
                splits: splits
            };

            this.sendShipmentRequest('dexpress_create_multiple_shipments', data);
        },

        /**
         * Validate shipment data
         */
        validateShipmentData: function(data) {
            if (!data.sender_location_id) {
                alert('Morate izabrati lokaciju pošiljaoca!');
                $('select[name="dexpress_sender_location_id"]').focus();
                return false;
            }
            return true;
        },

        /**
         * Send shipment request
         */
        sendShipmentRequest: function(action, data) {
            DExpressMetabox.state.isProcessing = true;
            
            // Update button state
            $('#dexpress-create-shipment')
                .prop('disabled', true)
                .html('<svg class="animate-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> Kreiranje...');

            data.action = action;
            data.nonce = DExpressMetabox.config.nonces.admin;

            console.log('[DExpress] Sending shipment request:', {action, data});

            $.ajax({
                url: DExpressMetabox.config.ajaxUrl,
                type: 'POST',
                data: data,
                success: (response) => {
                    console.log('[DExpress] Shipment response:', response);
                    
                    if (response.success) {
                        UIManager.showSuccess(response.data.message);
                        // Reload page after delay to show new shipments
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        UIManager.showError(response.data.message);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[DExpress] Shipment error:', {xhr, status, error});
                    UIManager.showError('Greška u komunikaciji sa serverom');
                },
                complete: () => {
                    DExpressMetabox.state.isProcessing = false;
                    
                    // Reset button state
                    $('#dexpress-create-shipment')
                        .prop('disabled', false)
                        .html(`<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg><span id="dexpress-create-text">${this.selectedPackageType === 'single' ? 'Kreiraj paket' : 'Kreiraj sve pakete'}</span>`);
                }
            });
        },

        /**
         * Update single package content automatically
         */
        updateSingleContent: function() {
            if (this.selectedPackageType !== 'single') return;
            
            // Use the helper function to generate content
            const orderId = DExpressMetabox.state.orderId;
            
            $.ajax({
                url: DExpressMetabox.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dexpress_generate_single_content',
                    order_id: orderId,
                    nonce: DExpressMetabox.config.nonces.admin
                },
                success: (response) => {
                    if (response.success && response.data.content) {
                        $('#dexpress_content').val(response.data.content);
                    }
                },
                error: () => {
                    console.warn('[DExpress] Failed to auto-generate content');
                }
            });
        },

        /**
         * Populate initial content on page load
         */
        populateInitialContent: function() {
            // This will be called when the page loads to set the default content
            const orderId = DExpressMetabox.state.orderId;
            
            $.ajax({
                url: DExpressMetabox.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dexpress_generate_single_content',
                    order_id: orderId,
                    nonce: DExpressMetabox.config.nonces.admin
                },
                success: (response) => {
                    if (response.success && response.data.content) {
                        $('#dexpress_content').val(response.data.content);
                    }
                }
            });
        }
    };

    // Weight Management Module
    const WeightManager = {
        /**
         * Bind weight-related events
         */
        bind: function () {
            console.log('[DExpress] WeightManager binding events');

            $(document)
                .on('click', '#dexpress-toggle-weights', this.toggleEditMode.bind(this))
                .on('input', '.weight-input', this.handleWeightChange.bind(this))
                .on('click', '#save-weight-changes', this.saveWeights.bind(this))
                .on('click', '#cancel-weight-changes', this.cancelEdit.bind(this));
        },

        /**
         * Toggle weight editing mode
         */
        toggleEditMode: function (e) {
            e.preventDefault();

            console.log('[DExpress] Toggling weight edit mode');

            const button = $(e.currentTarget);
            const isEditing = $('.weight-input:visible').length > 0;

            if (isEditing) {
                this.exitEditMode();
                button.text('Uredi težine');
            } else {
                this.enterEditMode();
                button.text('Otkaži');
            }
        },

        /**
         * Enter weight editing mode
         */
        enterEditMode: function () {
            console.log('[DExpress] Entering weight edit mode');

            $('.weight-display').hide();
            $('.weight-input').show();
            $('#weight-edit-controls').show();
            DExpressMetabox.state.editMode = true;
        },

        /**
         * Exit weight editing mode
         */
        exitEditMode: function () {
            console.log('[DExpress] Exiting weight edit mode');

            $('.weight-display').show();
            $('.weight-input').hide();
            $('#weight-edit-controls').hide();
            $('#weight-save-status').html('');
            DExpressMetabox.state.editMode = false;
        },

        /**
         * Handle weight input changes
         */
        handleWeightChange: function (e) {
            const input = $(e.currentTarget);
            const itemId = input.data('item-id');
            const quantity = input.data('quantity');
            const newWeight = parseFloat(input.val()) || 0;
            const itemTotal = newWeight * quantity;

            // Update item total display
            $(`tr[data-item-id="${itemId}"] .item-total-weight`).text(itemTotal.toFixed(2) + ' kg');

            // Update grand total
            this.updateTotalWeight();
        },

        /**
         * Update total weight calculation
         */
        updateTotalWeight: function () {
            let total = 0;
            $('.weight-input').each(function () {
                const weight = parseFloat($(this).val()) || 0;
                const quantity = $(this).data('quantity');
                total += (weight * quantity);
            });
            $('#total-order-weight').text(total.toFixed(2));
        },

        /**
         * Save weight changes via AJAX
         */
        saveWeights: function (e) {
            e.preventDefault();

            console.log('[DExpress] Saving weight changes');

            const button = $(e.currentTarget);
            const status = $('#weight-save-status');

            if (DExpressMetabox.state.isProcessing) {
                console.log('[DExpress] Already processing, ignoring save request');
                return;
            }

            const weights = this.collectWeights();

            if (!this.hasWeightChanges(weights)) {
                status.html('<span style="color: orange;">Nema promena za čuvanje</span>');
                return;
            }

            this.performSaveRequest(weights, button, status);
        },

        /**
         * Collect weight values
         */
        collectWeights: function () {
            const weights = {};
            $('.weight-input').each(function () {
                const itemId = $(this).data('item-id');
                weights[itemId] = parseFloat($(this).val()) || 0;
            });
            return weights;
        },

        /**
         * Check if weights have changed
         */
        hasWeightChanges: function (newWeights) {
            let hasChanges = false;
            $('.weight-input').each(function () {
                const itemId = $(this).data('item-id');
                const newWeight = newWeights[itemId];
                const originalWeight = parseFloat($(this).closest('tr').find('.weight-display').text()) || 0;

                if (Math.abs(newWeight - originalWeight) > 0.01) {
                    hasChanges = true;
                    return false; // break
                }
            });
            return hasChanges;
        },

        /**
         * Perform AJAX save request
         */
        performSaveRequest: function (weights, button, status) {
            DExpressMetabox.state.isProcessing = true;
            button.prop('disabled', true).text(DExpressMetabox.config.strings?.processing || 'Čuvam...');
            status.html('');

            $.ajax({
                url: DExpressMetabox.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'dexpress_save_custom_weights',
                    order_id: DExpressMetabox.state.orderId,
                    weights: weights,
                    nonce: DExpressMetabox.config.nonces?.metabox
                },
                success: (response) => {
                    console.log('[DExpress] Weight save response:', response);

                    if (response.success) {
                        const msg = DExpressMetabox.config.strings?.weightsUpdated || 'Težine ažurirane';
                        status.html(`<span style="color: green;">✓ ${msg}</span>`);
                        this.updateDisplayWeights(weights);
                        setTimeout(() => this.exitEditMode(), 1500);
                    } else {
                        const errMsg = DExpressMetabox.config.strings?.error || 'Greška';
                        status.html(`<span style="color: red;">${errMsg}: ${response.data}</span>`);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[DExpress] Weight save error:', { xhr, status, error });
                    const commErr = DExpressMetabox.config.strings?.communicationError || 'Greška u komunikaciji';
                    $('#weight-save-status').html(`<span style="color: red;">${commErr}</span>`);
                },
                complete: () => {
                    DExpressMetabox.state.isProcessing = false;
                    button.prop('disabled', false).text('Sačuvaj izmene');
                }
            });
        },

        /**
         * Update weight displays after save
         */
        updateDisplayWeights: function (weights) {
            $('.weight-input').each(function () {
                const itemId = $(this).data('item-id');
                const newWeight = weights[itemId];
                $(this).closest('tr').find('.weight-display').text(newWeight.toFixed(2) + ' kg');
            });
        },

        /**
         * Cancel weight editing
         */
        cancelEdit: function (e) {
            e.preventDefault();
            this.exitEditMode();
            $('#dexpress-toggle-weights').text('Uredi težine');
        }
    };

    // Shipment Creation Module (Legacy support)
    const ShipmentCreator = {
        bind: function () {
            console.log('[DExpress] ShipmentCreator binding events (legacy mode)');
            $(document)
                .on('click', '#dexpress-create-single-shipment', this.createSingleShipment.bind(this))
                .on('click', '#dexpress-toggle-split-mode', this.toggleSplitMode.bind(this))
                .on('click', '#dexpress-back-to-single', this.backToSingle.bind(this));
        },

        createSingleShipment: function (e) {
            e.preventDefault();
            if (DExpressMetabox.state.isProcessing) return;

            const formData = {
                order_id: DExpressMetabox.state.orderId,
                sender_location_id: $('select[name="dexpress_sender_location_id"]').val(),
                content: $('input[name="dexpress_content"]').val(),
                return_doc: $('input[name="dexpress_return_doc"]:checked').length > 0 ? 1 : 0,
                dispenser_id: $('input[name="dexpress_dispenser_id"]').val()
            };

            if (!formData.sender_location_id) {
                alert('Morate izabrati lokaciju!');
                return;
            }

            DExpressMetabox.state.isProcessing = true;
            formData.action = 'dexpress_create_shipment';
            formData.nonce = DExpressMetabox.config.nonces?.admin;

            $.ajax({
                url: DExpressMetabox.config.ajaxUrl,
                type: 'POST',
                data: formData,
                success: (response) => {
                    if (response.success) {
                        UIManager.showSuccess(response.data.message);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        UIManager.showError(response.data.message);
                    }
                },
                error: () => {
                    UIManager.showError('Greška u komunikaciji');
                },
                complete: () => {
                    DExpressMetabox.state.isProcessing = false;
                }
            });
        },

        toggleSplitMode: function (e) {
            e.preventDefault();
            DExpressMetabox.elements.createSection.hide();
            DExpressMetabox.elements.splitSection.show();
        },

        backToSingle: function (e) {
            e.preventDefault();
            DExpressMetabox.elements.splitSection.hide();
            DExpressMetabox.elements.createSection.show();
            $('#dexpress-splits-container').empty();
        }
    };

    // UI Management Module
    const UIManager = {
        showSuccess: function (message) {
            console.log('[DExpress] Showing success:', message);
            DExpressMetabox.elements.responseDiv.html(
                `<div class="notice notice-success"><p>${message}</p></div>`
            );
        },

        showError: function (message) {
            console.log('[DExpress] Showing error:', message);
            const errMsg = DExpressMetabox.config.strings?.error || 'Greška';
            DExpressMetabox.elements.responseDiv.html(
                `<div class="notice notice-error"><p>${errMsg}: ${message}</p></div>`
            );
        },

        clearMessages: function () {
            DExpressMetabox.elements.responseDiv.html('');
        }
    };

    // Label Management Module
    const LabelManager = {
        bind: function () {
            console.log('[DExpress] LabelManager binding events');

            $(document)
                .on('click', '.dexpress-get-single-label', this.downloadSingleLabel.bind(this))
                .on('click', '.dexpress-bulk-download-labels', this.downloadBulkLabels.bind(this));
        },

        downloadSingleLabel: function (e) {
            e.preventDefault();
            const shipmentId = $(e.currentTarget).data('shipment-id');
            const nonce = DExpressMetabox.config.nonces?.downloadLabel;
            const url = `${DExpressMetabox.config.ajaxUrl}?action=dexpress_download_label&shipment_id=${shipmentId}&nonce=${nonce}`;
            window.open(url, '_blank');
        },

        downloadBulkLabels: function (e) {
            e.preventDefault();
            const shipmentIds = $(e.currentTarget).data('shipment-ids');
            const nonce = DExpressMetabox.config.nonces?.bulkPrint;
            const url = `${DExpressMetabox.config.ajaxUrl}?action=dexpress_bulk_print_labels&shipment_ids=${shipmentIds}&_wpnonce=${nonce}`;
            window.open(url, '_blank');
        }
    };

    // Split Package Management Module
    const SplitPackageManager = {
        bind: function () {
            console.log('[DExpress] SplitPackageManager binding events');

            $(document)
                .on('click', '#dexpress-generate-splits', this.generateSplits.bind(this))
                .on('click', '#dexpress-create-all-shipments', this.createAllShipments.bind(this))
                .on('click', '.dexpress-remove-split', this.removeSplit.bind(this));
        },

        generateSplits: function (e) {
            e.preventDefault();
            const count = parseInt($('#dexpress-split-count').val()) || 2;
            const validCount = Math.max(2, Math.min(20, count));

            $('#dexpress-splits-container').empty();
            for (let i = 1; i <= validCount; i++) {
                this.addSplitForm(i, validCount);
            }
        },

        addSplitForm: function (index, total) {
            const html = this.buildSplitFormHTML(index, total);
            $('#dexpress-splits-container').append(html);
            this.attachSplitEventListeners(index);
        },

        buildSplitFormHTML: function (index, total) {
            const locations = DExpressMetabox.state.locations;
            const orderItems = DExpressMetabox.state.orderItems;

            let html = `<div class="dexpress-split-form" data-split-index="${index}" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #f9f9f9;">`;

            // Header
            html += `<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">`;
            html += `<h5 style="margin: 0;">Paket #${index}</h5>`;
            html += `<button type="button" class="button button-small dexpress-remove-split">Ukloni</button>`;
            html += `</div>`;

            // Location selector
            html += `<div style="margin-bottom: 15px;">`;
            html += `<label style="display: block; font-weight: bold; margin-bottom: 5px;">Lokacija:</label>`;
            html += `<select name="split_locations[]" class="split-location-select" style="width: 100%;" required>`;
            html += `<option value="">Izaberite lokaciju...</option>`;

            locations.forEach(function (location) {
                html += `<option value="${location.id}">${location.name} - ${location.address}</option>`;
            });

            html += `</select></div>`;

            // Content input
            html += `<div style="margin-bottom: 15px;">`;
            html += `<label style="display: block; font-weight: bold; margin-bottom: 5px;">Sadržaj paketa:</label>`;
            html += `<input type="text" name="split_content[]" class="split-content-input" style="width: 100%;" maxlength="50" placeholder="Automatski će se generisati...">`;
            html += `</div>`;

            // Items selector
            html += `<div style="margin-bottom: 15px;">`;
            html += `<label style="display: block; font-weight: bold; margin-bottom: 5px;">Odaberite artikle:</label>`;
            html += `<div style="margin-bottom: 8px;">`;
            html += `<button type="button" class="button button-small select-all-package-items" data-split="${index}">Sve</button> `;
            html += `<button type="button" class="button button-small deselect-all-package-items" data-split="${index}">Ništa</button>`;
            html += `</div>`;

            html += `<div class="split-items-container" style="max-height: 250px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">`;

            // Generate checkboxes for each item quantity
            orderItems.forEach(function (item) {
                for (let i = 1; i <= item.quantity; i++) {
                    html += `<label style="display: block; margin-bottom: 6px; padding: 8px; cursor: pointer; border: 1px solid #eee; border-radius: 3px;" class="split-item-label">`;
                    html += `<input type="checkbox" class="split-item-checkbox" `;
                    html += `data-item-id="${item.id}" `;
                    html += `data-weight="${item.weight}" `;
                    html += `data-name="${item.name}" `;
                    html += `data-split="${index}" `;
                    html += `value="${item.id}_${i}" `;
                    html += `style="margin-right: 8px;">`;
                    html += `<strong>${item.name}</strong> (${item.weight}kg) - komad ${i}`;
                    html += `</label>`;
                }
            });

            html += `</div></div>`;

            // Weight summary
            html += `<div class="split-weight-section" style="background: #f0f8ff; padding: 12px; border-radius: 4px; margin-bottom: 15px;">`;
            html += `<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">`;
            html += `<span><strong>Automatska težina:</strong> <span class="split-auto-weight" data-split="${index}">0.00 kg</span></span>`;
            html += `<button type="button" class="button button-small toggle-custom-weight" data-split="${index}">Prilagodi težinu</button>`;
            html += `</div>`;

            html += `<div class="custom-weight-controls" data-split="${index}" style="display: none;">`;
            html += `<div style="display: flex; align-items: center; gap: 10px;">`;
            html += `<label>Prilagođena težina:</label>`;
            html += `<input type="number" class="split-custom-weight" data-split="${index}" step="0.01" min="0.1" max="34" style="width: 80px;" placeholder="0.00"> kg`;
            html += `<button type="button" class="button button-small apply-custom-weight" data-split="${index}">Primeni</button>`;
            html += `<button type="button" class="button button-small reset-auto-weight" data-split="${index}">Resetuj</button>`;
            html += `</div>`;
            html += `<p style="margin: 5px 0 0 0; font-size: 11px; color: #666;">Maksimalno 34kg po paketu</p>`;
            html += `</div>`;

            html += `<div style="margin-top: 8px; font-size: 14px;">`;
            html += `<strong>Finalna težina: <span class="split-final-weight" data-split="${index}">0.00 kg</span></strong>`;
            html += `</div>`;
            html += `</div>`;

            html += `</div>`;
            return html;
        },

        attachSplitEventListeners: function (index) {
            // Checkbox listeners
            $(`.split-item-checkbox[data-split="${index}"]`).on('change', () => {
                this.updateSplitCalculations(index);
                this.updateSplitHighlights(index);
            });

            // Select/Deselect all buttons
            $(`.select-all-package-items[data-split="${index}"]`).on('click', () => {
                $(`.split-item-checkbox[data-split="${index}"]`).prop('checked', true);
                this.updateSplitCalculations(index);
                this.updateSplitHighlights(index);
            });

            $(`.deselect-all-package-items[data-split="${index}"]`).on('click', () => {
                $(`.split-item-checkbox[data-split="${index}"]`).prop('checked', false);
                this.updateSplitCalculations(index);
                this.updateSplitHighlights(index);
            });

            // Weight controls
            this.attachWeightControls(index);
        },

        attachWeightControls: function (index) {
            $(`.toggle-custom-weight[data-split="${index}"]`).on('click', function () {
                const controls = $(`.custom-weight-controls[data-split="${index}"]`);
                const button = $(this);

                if (controls.is(':visible')) {
                    controls.hide();
                    button.text('Prilagodi težinu');
                } else {
                    controls.show();
                    button.text('Sakrij kontrole');
                    const autoWeight = parseFloat($(`.split-auto-weight[data-split="${index}"]`).text());
                    $(`.split-custom-weight[data-split="${index}"]`).attr('placeholder', autoWeight.toFixed(2));
                }
            });

            $(`.apply-custom-weight[data-split="${index}"]`).on('click', () => {
                const customWeight = parseFloat($(`.split-custom-weight[data-split="${index}"]`).val());
                if (customWeight && customWeight > 0 && customWeight <= 34) {
                    $(`.split-final-weight[data-split="${index}"]`).text(customWeight.toFixed(2) + ' kg');
                } else {
                    alert('Unesite važeću težinu (0.1-34 kg)');
                }
            });

            $(`.reset-auto-weight[data-split="${index}"]`).on('click', () => {
                const autoWeight = parseFloat($(`.split-auto-weight[data-split="${index}"]`).text());
                $(`.split-final-weight[data-split="${index}"]`).text(autoWeight.toFixed(2) + ' kg');
                $(`.split-custom-weight[data-split="${index}"]`).val('');
            });
        },

        updateSplitCalculations: function (splitIndex) {
            let totalWeight = 0;
            let selectedItems = [];

            $(`.split-item-checkbox[data-split="${splitIndex}"]:checked`).each(function () {
                const weight = parseFloat($(this).data('weight')) || 0;
                const itemName = $(this).data('name');
                totalWeight += weight;
                selectedItems.push(itemName);
            });

            // Update auto weight
            $(`.split-auto-weight[data-split="${splitIndex}"]`).text(totalWeight.toFixed(2) + ' kg');

            // Update final weight if not custom
            const hasCustomWeight = $(`.split-custom-weight[data-split="${splitIndex}"]`).val();
            if (!hasCustomWeight) {
                $(`.split-final-weight[data-split="${splitIndex}"]`).text(totalWeight.toFixed(2) + ' kg');
            }

            // Update content
            this.updateSplitContent(splitIndex, selectedItems);
        },

        updateSplitContent: function (splitIndex, selectedItems) {
            if (selectedItems.length > 0) {
                // Dobij item IDs iz checkboxova
                const selectedItemIds = [];
                $(`.split-item-checkbox[data-split="${splitIndex}"]:checked`).each(function () {
                    const itemId = $(this).data('item-id');
                    if (!selectedItemIds.includes(itemId)) {
                        selectedItemIds.push(itemId);
                    }
                });

                // AJAX zahtev za server-side content generation
                $.ajax({
                    url: DExpressMetabox.config.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dexpress_generate_split_content',
                        order_id: DExpressMetabox.state.orderId,
                        selected_items: selectedItemIds,
                        nonce: DExpressMetabox.config.nonces.admin
                    },
                    success: (response) => {
                        if (response.success && response.data.content) {
                            $(`.dexpress-split-form[data-split-index="${splitIndex}"] .split-content-input`).val(response.data.content);
                        } else {
                            this.fallbackContentGeneration(splitIndex, selectedItems);
                        }
                    },
                    error: () => {
                        this.fallbackContentGeneration(splitIndex, selectedItems);
                    }
                });
            } else {
                $(`.dexpress-split-form[data-split-index="${splitIndex}"] .split-content-input`).val('');
            }
        },

        fallbackContentGeneration: function (splitIndex, selectedItems) {
            let content = '';
            if (selectedItems.length > 0) {
                const uniqueItems = [...new Set(selectedItems)];
                content = uniqueItems.slice(0, 3).join(', ');
                if (uniqueItems.length > 3) {
                    content += '...';
                }
                if (content.length > 47) {
                    content = content.substring(0, 47) + '...';
                }
            }
            $(`.dexpress-split-form[data-split-index="${splitIndex}"] .split-content-input`).val(content);
        },

        updateSplitHighlights: function (splitIndex) {
            $(`.split-item-checkbox[data-split="${splitIndex}"]`).each(function () {
                const label = $(this).closest('label');
                if ($(this).is(':checked')) {
                    label.css({
                        'background-color': '#e8f4fd',
                        'border-color': '#0073aa',
                        'font-weight': 'bold'
                    });
                } else {
                    label.css({
                        'background-color': '',
                        'border-color': '#eee',
                        'font-weight': 'normal'
                    });
                }
            });
        },

        removeSplit: function (e) {
            e.preventDefault();
            if (confirm('Ukloniti ovaj paket?')) {
                $(e.currentTarget).closest('.dexpress-split-form').remove();
                this.renumberSplitForms();
            }
        },

        renumberSplitForms: function () {
            $('.dexpress-split-form').each(function (index) {
                const newIndex = index + 1;
                $(this).attr('data-split-index', newIndex);
                $(this).find('h5').html('Paket #' + newIndex);
            });
        },

        createAllShipments: function (e) {
            e.preventDefault();

            if (DExpressMetabox.state.isProcessing) {
                return;
            }

            const splits = this.collectSplitData();

            if (splits.length === 0) {
                alert('Morate definisati barem jedan paket!');
                return;
            }

            const data = {
                order_id: DExpressMetabox.state.orderId,
                splits: splits,
                action: 'dexpress_create_multiple_shipments',
                nonce: DExpressMetabox.config.nonces.admin
            };

            DExpressMetabox.state.isProcessing = true;

            $.ajax({
                url: DExpressMetabox.config.ajaxUrl,
                type: 'POST',
                data: data,
                success: (response) => {
                    if (response.success) {
                        UIManager.showSuccess(response.data.message);
                        setTimeout(() => location.reload(), 3000);
                    } else {
                        UIManager.showError(response.data.message);
                    }
                },
                error: () => {
                    UIManager.showError('Greška u komunikaciji sa serverom');
                },
                complete: () => {
                    DExpressMetabox.state.isProcessing = false;
                }
            });
        },

        collectSplitData: function () {
            const splits = [];

            $('.dexpress-split-form').each(function (index) {
                const splitIndex = index + 1;
                const locationId = $(this).find('select[name="split_locations[]"]').val();
                const customContent = $(this).find('.split-content-input').val();
                const finalWeight = parseFloat($(`.split-final-weight[data-split="${splitIndex}"]`).text()) || 0;

                // Collect selected items with their quantities
                const selectedItemsData = {};
                $(`.split-item-checkbox[data-split="${splitIndex}"]:checked`).each(function () {
                    const itemId = $(this).data('item-id');
                    if (!selectedItemsData[itemId]) {
                        selectedItemsData[itemId] = 0;
                    }
                    selectedItemsData[itemId]++;
                });

                if (locationId && Object.keys(selectedItemsData).length > 0) {
                    splits.push({
                        location_id: locationId,
                        items: selectedItemsData,
                        custom_content: customContent,
                        final_weight: finalWeight
                    });
                }
            });

            return splits;
        }
    };

    // Add spinning animation CSS for loading button
    const loadingCSS = `
    .animate-spin {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    `;

    // Add CSS to head
    if (!document.querySelector('#dexpress-loading-styles')) {
        $('head').append(`<style id="dexpress-loading-styles">${loadingCSS}</style>`);
    }

    // Initialize when DOM is ready
    $(document).ready(function () {
        console.log('[DExpress] DOM ready, checking for metabox...');

        // Only initialize if metabox exists
        if ($('.dexpress-order-metabox').length) {
            console.log('[DExpress] Metabox found, initializing...');

            // Initialize main controller
            DExpressMetabox.init();
            
            // Initialize modern workflow
            ModernWorkflow.init();

            // Bind all modules
            WeightManager.bind();
            ShipmentCreator.bind();
            LabelManager.bind();
            SplitPackageManager.bind();

            // Expose for debugging
            window.DExpressMetabox = DExpressMetabox;
            window.ModernWorkflow = ModernWorkflow;
            window.DExpressModules = {
                WeightManager,
                ShipmentCreator,
                UIManager,
                LabelManager,
                SplitPackageManager
            };

            console.log('[DExpress] Modern workflow initialized and exposed to window');
        } else {
            console.log('[DExpress] Metabox not found on this page');
        }
    });

})(jQuery, window, document);