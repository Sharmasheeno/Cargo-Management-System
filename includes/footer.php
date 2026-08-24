</div> <!-- End of main-content -->

<footer class="footer mt-auto py-3 text-center" id="huFooter" style="background: white; border-top: 1px solid #E9E7F1; transition: all 0.3s ease; position: relative;">
    <div class="container-fluid">
        <span class="text-muted" style="font-size: 14px; font-weight: 500;">
            <?php
                // We use the $system_name defined in header.php if available, 
                // otherwise we fetch it again (for pages that might only include footer)
                if (!isset($system_name)) {
                    $system_name = 'Cargo Management System';
                    $current_tenant_id = $_SESSION['tenant_id'] ?? null;
                    if ($current_tenant_id) {
                        try {
                            $stmt_name = $pdo->prepare("SELECT setting_value FROM system_settings WHERE tenant_id = ? AND setting_key = 'system_name' LIMIT 1");
                            $stmt_name->execute([$current_tenant_id]);
                            $custom_name = $stmt_name->fetchColumn();
                            if ($custom_name) $system_name = $custom_name;
                        } catch (PDOException $e) {}
                    }
                }
            ?>
            &copy; <span id="currentYear"></span> <?= htmlspecialchars($system_name) ?>. All rights reserved. | Cargo Management System
        </span>
        <div style="font-size: 11px; color: #9b96a8; margin-top: 2px;">
            Built by <strong style="color:#2D1859;">CURDUN ICT</strong>
        </div>
    </div>
</footer>
<!-- Standard Scripts -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
// Mobile navigation
let isMobile = window.innerWidth <= 768;
const sidebar = document.getElementById("sidebar");
const toggleBtn = document.getElementById("sidebarToggle");
const toggleIcon = document.getElementById("toggleIcon");
const body = document.body;
let sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

// Footer Responsive Function
function updateFooterPosition() {
  const footer = document.getElementById('huFooter');
  
  if (isMobile) {
    footer.style.left = '0';
    footer.style.width = '100%';
  } else {
    if (sidebarCollapsed) {
      footer.style.marginLeft = '60px';
      footer.style.width = 'calc(100% - 60px)';
    } else {
      footer.style.marginLeft = '240px';
      footer.style.width = 'calc(100% - 240px)';
    }
  }
}

// Initialize sidebar state
function initializeSidebar() {
    if (isMobile) {
        sidebar.classList.remove('collapsed', 'mobile-open');
        body.classList.remove('sidebar-collapsed');
        toggleIcon.classList.remove('fa-xmark');
        toggleIcon.classList.add('fa-bars');
    } else {
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            body.classList.add('sidebar-collapsed');
            toggleIcon.classList.remove('fa-bars');
            toggleIcon.classList.add('fa-xmark');
        } else {
            sidebar.classList.remove('collapsed');
            body.classList.remove('sidebar-collapsed');
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
    }
    updateFooterPosition();
}

// Check screen size
function checkMobile() {
    isMobile = window.innerWidth <= 768;
    initializeSidebar();
}

// Toggle sidebar
toggleBtn.addEventListener('click', function() {
    if (isMobile) {
        sidebar.classList.toggle('mobile-open');
        toggleIcon.classList.toggle('fa-bars');
        toggleIcon.classList.toggle('fa-xmark');
    } else {
        sidebarCollapsed = !sidebarCollapsed;
        
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            body.classList.add('sidebar-collapsed');
            toggleIcon.classList.remove('fa-bars');
            toggleIcon.classList.add('fa-xmark');
        } else {
            sidebar.classList.remove('collapsed');
            body.classList.remove('sidebar-collapsed');
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
        
        localStorage.setItem('sidebarCollapsed', sidebarCollapsed);
        updateFooterPosition();
    }
});

// Close mobile sidebar when clicking outside
document.addEventListener('click', function(event) {
    if (isMobile && sidebar.classList.contains('mobile-open')) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('mobile-open');
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
    }
});

// Submenu toggle
document.querySelectorAll(".menu-toggle").forEach(toggle => {
    toggle.addEventListener('click', function(e) {
        if (isMobile) e.stopPropagation();
        
        const submenu = document.getElementById(this.dataset.target);
        if (submenu.style.display === "block") {
            submenu.style.display = "none";
            this.classList.remove("open");
        } else {
            submenu.style.display = "block";
            this.classList.add("open");
        }
    });
});

// Profile modal
const profileModal = document.getElementById("profileModal");
document.getElementById("profileOpen").addEventListener('click', () => {
    profileModal.style.display = "flex";
    document.body.style.overflow = "hidden";
});

document.getElementById("closeProfile").addEventListener('click', () => {
    profileModal.style.display = "none";
    document.body.style.overflow = "auto";
});

document.addEventListener('DOMContentLoaded', function() {
    const yearSpan = document.getElementById('currentYear');
    if (yearSpan) {
        yearSpan.textContent = new Date().getFullYear();
    }
});

window.addEventListener('click', function(event) {
    if (event.target === profileModal) {
        profileModal.style.display = "none";
        document.body.style.overflow = "auto";
    }
});

checkMobile();
window.addEventListener('resize', checkMobile);
</script>