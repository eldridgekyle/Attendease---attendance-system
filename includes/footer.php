  </div>
  <footer class="page-footer"><span>© <?= date('Y') ?> AttendEase</span><span>Attendance made simple and reliable.</span><a href="mailto:support@attendease.test">Help & support</a></footer>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('keydown', function (event) {
  if (event.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
    event.preventDefault();
    document.querySelector('.top-search input')?.focus();
  }
});
</script>
</body>
</html>
