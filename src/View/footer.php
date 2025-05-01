<?php

    if (!isset($datatable_order)) {
        $datatable_order = '[]';
    }
    if (!isset($datatable_settings)) {
        $datatable_settings = '';
    }

    require_once "/var/www/itflow-ng/includes/inc_confirm_modal.php";
    require_once "/var/www/itflow-ng/includes/inc_dynamic_modal.php";
    function renderMenuItems($items, $level = 0) {
        $firstItem = true; // Track the first item at this level
    
        foreach ($items as $index => $item) {
            if (!$firstItem) {
                echo '<span class="px-2">|</span>'; // Add separator before the item if it's not the first
            }
    
            echo '<div class="d-inline">'; // Inline display using Bootstrap
    
            // Check if a link is provided and display accordingly
            if (!empty($item['link'])) {
                echo '<a href="' . htmlspecialchars($item['link']) . '" class="footer-link">' . htmlspecialchars($item['title']) . '</a>';
            } else {
                echo '<span>' . htmlspecialchars($item['title']) . '</span>'; // Non-link text
            }
    
            // If there are children, show them with a hierarchy visual cue
            if (!empty($item['children'])) {
                echo ' <span class="text-muted">></span> '; // Visual cue for hierarchy
                renderMenuItems($item['children'], $level + 1);
            }
    
            echo '</div>';
    
            $firstItem = false; // After the first item has been rendered, set this to false
        }
    }

    if (!isset($datatable_order)) {
        $datatable_order = '[]';
    }

?>

<footer class="content-footer footer bg-footer-theme">
    <div class="container-fluid pt-5 pb-4">
        <div class="row">
            <div class="row">
                <div class="col-12 col-sm-3 col-md-2 mb-4 mb-sm-4 d-print-none">
                    <h4 class="fw-bold mb-3"><a href="https://twe.tech" target="_blank" class="footer-text">ITFlow-NG </a></h4>        <span>Get ready for a better ERP.</span>
                    <div class="social-icon my-3">
                    <a href="javascript:void(0)" class="btn btn-icon btn-sm btn-facebook"><i class='bx bxl-facebook'></i></a>
                    <a href="javascript:void(0)" class="ms-2 btn btn-icon btn-sm btn-twitter"><i class='bx bxl-twitter'></i></a>
                    <a href="javascript:void(0)" class="ms-2 btn btn-icon btn-sm btn-linkedin"><i class='bx bxl-linkedin'></i></a>
                    </div>
                    <p class="pt-4">
                    <script>
                    document.write(new Date().getFullYear())
                    </script> © TWE Technologies
                    </p>
                </div>
            </div>
            <div class="row">
            </div>
        </div>
    </div>
</footer>

<!-- Overlay -->
<div class="layout-overlay layout-menu-toggle"></div>

<!-- Drag Target Area To SlideIn Menu On Small Screens -->
<div class="drag-target"></div>
</div>
<!-- / Layout wrapper -->




<!-- Core JS -->
<!-- build:js assets/vendor/js/core.js -->

<script src="/includes/assets/vendor/libs/popper/popper.js"></script>
<script src="/includes/assets/vendor/js/bootstrap.js"></script>
<script src="/includes/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="/includes/assets/vendor/libs/hammer/hammer.js"></script>
<script src="/includes/assets/vendor/libs/i18n/i18n.js"></script>
<script src="/includes/assets/vendor/libs/typeahead-js/typeahead.js"></script>
<script src="/includes/assets/vendor/js/menu.js"></script>

<script src="https://cdn.datatables.net/2.0.3/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.0.3/js/dataTables.bootstrap5.js"></script>
<script src="https://cdn.datatables.net/responsive/3.0.1/js/dataTables.responsive.js"></script>
<script src="https://cdn.datatables.net/responsive/3.0.1/js/responsive.bootstrap5.js"></script>


<script src="/includes/assets/vendor/js/menu.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCa21nqFlNCdPX3TXhaEyyJ_vk9Icpqhu0&libraries=places"></script>

<!-- endbuild -->

<!-- Vendors JS -->

<script src="/includes/assets/vendor/libs/plyr/plyr.js"></script>
<script src="/includes/assets/vendor/libs/block-ui/block-ui.js"></script>
<script src="/includes/assets/vendor/libs/sortablejs/sortable.js"></script>
<script src="/includes/assets/vendor/libs/toastr/toastr.js"></script>
<script src="/includes/plugins/moment/moment.min.js"></script>
<script src="/includes/plugins/chart.js/Chart.min.js"></script>
<script src="/includes/assets/vendor/libs/flatpickr/flatpickr.js"></script>
<script src="/includes/assets/vendor/libs/cleavejs/cleave.js"></script>
<script src="/includes/assets/vendor/libs/cleavejs/cleave-phone.js"></script>
<script src="/includes/assets/vendor/libs/jquery-repeater/jquery-repeater.js"></script>
<script src="/includes/js/header_timers.js"></script>
<script src="/includes/js/reformat_datetime.js"></script>
<script src="/includes/plugins/select2/js/select2.min.js"></script>
<script src="/includes/js/ticket_time_tracking.js"></script>



<!-- Main JS -->
<script src="/includes/assets/js/main.js"></script>

<script src="/includes/js/dynamic_modal_loading.js"></script>

<!-- Page JS -->

<script>
document.querySelectorAll('textarea').forEach(function(textarea) {
    textarea.addEventListener('click', function initTinyMCE() {
        // This check ensures that TinyMCE is initialized only once for each textarea
        if (!tinymce.get(this.id)) {
            console.log('Initializing TinyMCE for:', this.id); // Debug log
            tinymce.init({
                selector: '#' + this.id,
                plugins: 'autosave link image media wordcount powerpaste table autolink autoresize fullscreen help lists advlist preview searchreplace visualchars',
                toolbar: 'fullscreen | undo redo searchreplace | restoredraft | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | typography | align lineheight | checklist numlist bullist indent outdent | emoticons charmap | removeformat | help',
                promotion: false,
                newline_behavior: 'block',
                autosave_retention: '480m',
                autosave_prefix: 'tinymce-autosave-' + this.id + '-',
                autosave_restore_when_empty: true,
                autosave_interval: '5s',
                browser_spellcheck: true,

            });
        }
    }, { once: true });
});

// Send Invoice Email
$('.sendInvoiceEmailBtn').click(function() {
    var invoice_id = $(this).data('invoice-id');
    // Make AJAX call to send email
    var url = '/ajax/ajax.php?send_invoice_email=' + invoice_id;
    $.ajax({
        type: 'POST',
        url: url,
        success: function(response) {
            alert('Email sent successfully');
            // hide the send email button and show the Add Payment and Cancel buttons
            $('.sendInvoiceEmailBtn[data-invoice-id="' + invoice_id + '"]').hide();

            $('.addPaymentBtn[data-invoice-id="' + invoice_id + '"]').show();
            $('.cancelInvoiceBtn[data-invoice-id="' + invoice_id + '"]').show();

            //update the status of the invoice
            $('.invoiceStatus[data-invoice-id="' + invoice_id + '"]').text('Sent');
            
        },
        error: function() {
            alert('Error sending email');
        }
    });
});

// Set up the Intersection Observer
const observerOptions = {
    root: null,
    rootMargin: '0px',
    threshold: 0.1
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            requestAnimationFrame(() => {
                entry.target.classList.add('visible');
            });
            observer.unobserve(entry.target);
        }
    });
}, observerOptions);

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    document.body.classList.add('content-loaded');
    
    // Observe all cards
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        observer.observe(card);
    });
});


$(function () {
    // Initialize DataTables
    var datatable = $('.datatables-basic').DataTable({
        processing: true,
        responsive: true,
        stateSave: true,
        order: [],
        autoWidth: false,
        scrollCollapse: true,
        language: {
            processing: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>'
        },
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
    });

    // Handle window resize
    $(window).on('resize', function() {
        datatable.columns.adjust().responsive.recalc();
    });

    // Handle tab changes if using Bootstrap tabs
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
        datatable.columns.adjust().responsive.recalc();
    });

    // Initialize Select2
    $(".select2").select2();

    // video
    const videoPlayer = new Plyr('#plyr-video-player');
    document.getElementsByClassName('plyr')[0].style.borderRadius = '7px';
    document.getElementsByClassName('plyr__poster')[0].style.display = 'none';

    // content sticky

    const htmlElement = document.getElementsByTagName('html')[0];
    const stick = document.querySelector('.stick-top');

    function TopSticky() {
        if (htmlElement.classList.contains('layout-navbar-fixed')) {
            stick.classList.add('course-content-fixed');
        } else {
            stick.classList.remove('course-content-fixed');
        }
    }

    TopSticky();
    window.onscroll = function() {
        TopSticky();
    }
    ;
});

</script>



<style>
/* Ensure this CSS is loaded after the Google Maps API CSS */
    .pac-container {
        z-index: 99999 !important; 
    }
    .select2-container {
        width: 80% !important; 
    }
    .select2-selection__rendered {
        width: 100% !important; 
    }
    .select2-selection {
        width: 100% !important; 
    }


</style>

<script src="/includes/assets/js/cards-actions.js"></script>
</body>
</html>