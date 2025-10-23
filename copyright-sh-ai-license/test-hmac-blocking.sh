#!/bin/bash
#
# Test HTTP 402 Payment Required blocking with HMAC license token validation
#
# Usage: ./test-hmac-blocking.sh [WORDPRESS_URL] [HMAC_SECRET]
#
# Example:
#   ./test-hmac-blocking.sh https://copyrightish.ddev.site mysecretkey123
#

# Configuration
WORDPRESS_URL="${1:-https://copyrightish.ddev.site}"
HMAC_SECRET="${2:-}"

if [ -z "$HMAC_SECRET" ]; then
    echo "Error: HMAC_SECRET is required"
    echo "Usage: $0 [WORDPRESS_URL] [HMAC_SECRET]"
    exit 1
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================="
echo "HTTP 402 Payment Required Blocking Tests"
echo "========================================="
echo ""
echo "WordPress URL: $WORDPRESS_URL"
echo "HMAC Secret: ${HMAC_SECRET:0:5}..."
echo ""

# Test 1: AI bot without token should get 402
echo -e "${YELLOW}Test 1: GPTBot without license token (should return 402)${NC}"
response=$(curl -s -w "\n%{http_code}" -A "GPTBot/1.0" "$WORDPRESS_URL" 2>/dev/null)
http_code=$(echo "$response" | tail -n 1)
body=$(echo "$response" | head -n -1)

if [ "$http_code" = "402" ]; then
    echo -e "${GREEN}✓ PASS${NC} - Got 402 Payment Required"
    echo "Response body:"
    echo "$body" | jq '.' 2>/dev/null || echo "$body"
else
    echo -e "${RED}✗ FAIL${NC} - Expected 402, got $http_code"
fi
echo ""

# Test 2: Search engine should get 200 (whitelisted)
echo -e "${YELLOW}Test 2: Googlebot (should return 200 - whitelisted)${NC}"
response=$(curl -s -w "\n%{http_code}" -A "Googlebot/2.1" "$WORDPRESS_URL" 2>/dev/null)
http_code=$(echo "$response" | tail -n 1)

if [ "$http_code" = "200" ]; then
    echo -e "${GREEN}✓ PASS${NC} - Got 200 OK (search engine whitelisted)"
else
    echo -e "${RED}✗ FAIL${NC} - Expected 200, got $http_code"
fi
echo ""

# Test 3: Generate valid HMAC token and test
echo -e "${YELLOW}Test 3: GPTBot with VALID HMAC token (should return 200)${NC}"
LICENSE_VERSION_ID="12345"
LICENSE_SIG=$(echo -n "$LICENSE_VERSION_ID" | openssl dgst -sha256 -hmac "$HMAC_SECRET" | awk '{print $2}')
echo "Generated token: ${LICENSE_VERSION_ID}-${LICENSE_SIG}"

response=$(curl -s -w "\n%{http_code}" -A "GPTBot/1.0" "${WORDPRESS_URL}?ai-license=${LICENSE_VERSION_ID}-${LICENSE_SIG}" 2>/dev/null)
http_code=$(echo "$response" | tail -n 1)

if [ "$http_code" = "200" ]; then
    echo -e "${GREEN}✓ PASS${NC} - Got 200 OK (valid HMAC token accepted)"
else
    echo -e "${RED}✗ FAIL${NC} - Expected 200, got $http_code"
    body=$(echo "$response" | head -n -1)
    echo "Response: $body"
fi
echo ""

# Test 4: Invalid HMAC signature should get 401
echo -e "${YELLOW}Test 4: GPTBot with INVALID HMAC token (should return 401)${NC}"
INVALID_SIG="deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef"

response=$(curl -s -w "\n%{http_code}" -A "GPTBot/1.0" "${WORDPRESS_URL}?ai-license=${LICENSE_VERSION_ID}-${INVALID_SIG}" 2>/dev/null)
http_code=$(echo "$response" | tail -n 1)
body=$(echo "$response" | head -n -1)

if [ "$http_code" = "401" ]; then
    echo -e "${GREEN}✓ PASS${NC} - Got 401 Unauthorized (invalid signature rejected)"
    echo "Response body:"
    echo "$body" | jq '.' 2>/dev/null || echo "$body"
else
    echo -e "${RED}✗ FAIL${NC} - Expected 401, got $http_code"
fi
echo ""

# Test 5: Malformed token should get 401
echo -e "${YELLOW}Test 5: GPTBot with MALFORMED token (should return 401)${NC}"

response=$(curl -s -w "\n%{http_code}" -A "GPTBot/1.0" "${WORDPRESS_URL}?ai-license=malformed-token" 2>/dev/null)
http_code=$(echo "$response" | tail -n 1)

if [ "$http_code" = "401" ]; then
    echo -e "${GREEN}✓ PASS${NC} - Got 401 Unauthorized (malformed token rejected)"
else
    echo -e "${RED}✗ FAIL${NC} - Expected 401, got $http_code"
fi
echo ""

# Test 6: Other AI bots should also get 402
echo -e "${YELLOW}Test 6: Claude-Web without token (should return 402)${NC}"

response=$(curl -s -w "\n%{http_code}" -A "Claude-Web/1.0" "$WORDPRESS_URL" 2>/dev/null)
http_code=$(echo "$response" | tail -n 1)

if [ "$http_code" = "402" ]; then
    echo -e "${GREEN}✓ PASS${NC} - Got 402 Payment Required"
else
    echo -e "${RED}✗ FAIL${NC} - Expected 402, got $http_code"
fi
echo ""

# Test 7: Regular browser should get 200
echo -e "${YELLOW}Test 7: Regular browser (should return 200)${NC}"

response=$(curl -s -w "\n%{http_code}" -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)" "$WORDPRESS_URL" 2>/dev/null)
http_code=$(echo "$response" | tail -n 1)

if [ "$http_code" = "200" ]; then
    echo -e "${GREEN}✓ PASS${NC} - Got 200 OK (regular user allowed)"
else
    echo -e "${RED}✗ FAIL${NC} - Expected 200, got $http_code"
fi
echo ""

echo "========================================="
echo "Test suite completed"
echo "========================================="
