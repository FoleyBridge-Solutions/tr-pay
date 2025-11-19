#!/bin/bash

echo "🔍 Checking for DB queries without explicit connection..."
echo ""
echo "⚠️ These files have DB queries that DON'T specify connection('sqlsrv'):"
echo "   (They will default to SQLite instead of SQL Server!)"
echo ""

# Find all DB::select, DB::table, DB::selectOne without connection('sqlsrv')
grep -rn "DB::\(select\|table\|selectOne\)" app/ --include="*.php" \
  | grep -v "connection('sqlsrv')" \
  | grep -v "ExtractDatabaseSchema" \
  | while IFS=: read -r file line content; do
    # Check if this line queries SQL Server tables
    if echo "$content" | grep -qE "(Client|Invoice|Ledger_Entry|Contact|Entity|Custom_Value|Staff)"; then
      echo "❌ $file:$line"
      echo "   $content"
      echo ""
    fi
  done

echo ""
echo "✅ Files that correctly use DB::connection('sqlsrv'):"
grep -rn "DB::connection('sqlsrv')" app/ --include="*.php" | cut -d: -f1 | sort -u | while read file; do
  echo "   $file"
done

echo ""
echo "📝 Remember: ALL queries to Client, Invoice, LedgerEntry, etc. MUST use:"
echo "   DB::connection('sqlsrv')->select(...)"
