// Automatically determine base URL based on environment
let API_BASE_URL;
if (window.location.protocol === 'file:') {
    API_BASE_URL = 'http://localhost/school attendance/';
} else {
    // Extract base path dynamically and decode it to avoid double-encoding spaces (e.g., %20)
    const decodedPath = decodeURIComponent(window.location.pathname);
    API_BASE_URL = decodedPath.substring(0, decodedPath.lastIndexOf('/') + 1);
}
