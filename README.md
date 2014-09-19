# Parity functions in PHP

This is a couple of very simple functions allowing to store an arbitrary
string in multiple strings and rebuild it if some of the generated strings are
missing (depending on the numbers defined when initially generating).

	raidSplit($string, $parts = 3, $min_req = 2)

This will return an array containing $parts entries. Note that the returned
data is binary and you will most usually want to apply something such as
base64\_encode() on it.

	raidRepair(array $data, $length)

This will reconnect data based on provided array and return the original
string.

Note that while it should be possible to check the received data by using
parity information as a kind of checksum, the focus of those two methods is
to deal with loss of data rather than corruption.
